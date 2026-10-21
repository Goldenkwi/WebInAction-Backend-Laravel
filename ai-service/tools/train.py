"""
================================================================================
SCRIPT FINE-TUNING MODEL
================================================================================
Meng-fine-tune ulang model IndoBERT P2 untuk klasifikasi kategori / urgensi.

Model lu SUDAH tersedia (hasil training sebelumnya), jadi script ini dipakai
untuk:
  1. Reproduksi - kalau mau memastikan ulang hasil training dari nol.
  2. Retraining - kalau dataset ditambah (mis. untuk menutup kesenjangan domain
     dengan data keluhan warga yang lebih realistis).

--------------------------------------------------------------------------------
CARA PAKAI (Google Colab - disarankan)
--------------------------------------------------------------------------------
    # 1. Upload folder ini ke Colab, lalu aktifkan GPU (Runtime > GPU).
    !pip install -q -r requirements.txt
    !python tools/train.py \
        --model indobenchmark/indobert-base-p2 \
        --train data/tupoksi/dataset_tupoksi_v2_train.csv \
        --test  data/tupoksi/dataset_tupoksi_v2_test.csv \
        --label-column kategori \
        --output models/category \
        --epochs 3

    # 2. Evaluasi + kalibrasi threshold
    !python tools/evaluate.py \
        --model models/category \
        --test data/tupoksi/dataset_tupoksi_v2_test.csv \
        --label-column kategori \
        --output reports/category_eval.md

--------------------------------------------------------------------------------
CATATAN TENTANG MAX_LENGTH
--------------------------------------------------------------------------------
Nilai max_length default mengikuti statistik panjang teks dataset:
  - model kategori  : 256 token
  - model prioritas : 128 token

Kalau data_train.csvmu dibuat dengan menggabungkan banyak kata, barisnya
biasanya pendek, jadi 128-256 token sudah lebih dari cukup.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path

import pandas as pd
import torch
from sklearn.metrics import classification_report
from sklearn.model_selection import train_test_split
from transformers import (
    AutoModelForSequenceClassification,
    AutoTokenizer,
    DataCollatorWithPadding,
    Trainer,
    TrainingArguments,
)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Fine-tune model klasifikasi IndoBERT.")
    parser.add_argument("--model", default="indobenchmark/indobert-base-p2")
    parser.add_argument("--train", required=True)
    parser.add_argument("--test", required=True)
    parser.add_argument("--text-column", default="teks")
    parser.add_argument("--label-column", default="kategori")
    parser.add_argument("--output", default="models/output")
    parser.add_argument("--epochs", type=int, default=3)
    parser.add_argument("--batch-size", type=int, default=16)
    parser.add_argument("--max-length", type=int, default=256)
    parser.add_argument("--learning-rate", type=float, default=2e-5)
    parser.add_argument("--batch", type=int, default=8, help="Ukuran batch inferensi saat evaluasi.")
    parser.add_argument("--seed", type=int, default=42)
    return parser.parse_args()


def main() -> int:
    args = parse_args()

    print("=" * 74)
    print("FINE-TUNING MODEL")
    print("=" * 74)
    print(f"Base model : {args.model}")
    print(f"Train set : {args.train}")
    print(f"Test set  : {args.test}")

    # ---------------- Data ----------------
    train_df = pd.read_csv(args.train)
    test_df = pd.read_csv(args.test)

    # Label harus berupa string yang konsisten (dipakai juga sebagai id2label).
    train_df[args.label_column] = train_df[args.label_column].astype(str).str.strip()
    test_df[args.label_column] = test_df[args.label_column].astype(str).str.strip()

    labels = sorted(train_df[args.label_column].unique())
    label2id = {label: index for index, label in enumerate(labels)}
    id2label = {index: label for label, index in label2id.items()}

    print(f"\nJumlah label : {len(labels)}")
    print(f"Label        : {labels}")

    # Split train -> train + validation (80/20) untuk memonitor overfitting.
    train_df, val_df = train_test_split(
        train_df,
        test_size=0.2,
        random_state=args.seed,
        stratify=train_df[args.label_column],
    )
    print(f"Train        : {len(train_df)}")
    print(f"Validation   : {len(val_df)}")
    print(f"Test         : {len(test_df)}")

    # ---------------- Tokenizer ----------------
    tokenizer = AutoTokenizer.from_pretrained(args.model)

    def tokenize(frame: pd.DataFrame) -> "pd.DataFrame":
        texts = frame[args.text_column].astype(str).tolist()
        labels_list = [label2id[x] for x in frame[args.label_column]]
        encoded = tokenizer(
            texts,
            truncation=True,
            max_length=args.max_length,
            padding=False,
        )
        encoded["labels"] = labels_list
        return pd.DataFrame(encoded)

    train_ds = tokenize(train_df)
    val_ds = tokenize(val_df)
    test_ds = tokenize(test_df)

    # ---------------- Model ----------------
    model = AutoModelForSequenceClassification.from_pretrained(
        args.model,
        num_labels=len(labels),
        id2label=id2label,
        label2id=label2id,
    )

    training_args = TrainingArguments(
        output_dir=args.output,
        num_train_epochs=args.epochs,
        per_device_train_batch_size=args.batch_size,
        per_device_eval_batch_size=args.batch_size,
        learning_rate=args.learning_rate,
        weight_decay=0.01,
        warmup_ratio=0.1,
        eval_strategy="epoch",           # evaluasi tiap epoch
        save_strategy="epoch",
        load_best_model_at_end=True,
        metric_for_best_model="macro_f1",
        greater_is_better=True,
        logging_steps=50,
        fp16=torch.cuda.is_available(),   # mixed precision kalau ada GPU
        seed=args.seed,
        report_to=[],                      # matikan integrasi wandb dll
    )

    trainer = Trainer(
        model=model,
        args=training_args,
        train_dataset=train_ds,
        eval_dataset=val_ds,
        data_collator=DataCollatorWithPadding(tokenizer),
        compute_metrics=compute_metrics,
    )

    print("\nMulai training...")
    trainer.train()

    # ---------------- Evaluasi di test set ----------------
    print("\nEvaluasi di test set...")
    predictions = trainer.predict(test_ds)
    predicted_ids = np_argmax(predictions.predictions)
    actual_ids = test_ds["labels"].tolist()

    target_names = [id2label[i] for i in range(len(labels))]
    report = classification_report(
        actual_ids, predicted_ids, target_names=target_names, zero_division=0
    )
    print("\n" + report)

    # ---------------- Simpan ----------------
    output_dir = Path(args.output)
    output_dir.mkdir(parents=True, exist_ok=True)
    trainer.save_model(str(output_dir))
    tokenizer.save_pretrained(str(output_dir))

    (output_dir / "label_map.json").write_text(
        json.dumps({"id2label": id2label, "label2id": label2id}, indent=2, ensure_ascii=False),
        encoding="utf-8",
    )
    (output_dir / "evaluation_report.txt").write_text(report, encoding="utf-8")

    print(f"Model disimpan ke: {output_dir}")
    print("Langkah berikutnya: jalankan tools/evaluate.py untuk kalibrasi threshold.")

    return 0


def np_argmax(matrix) -> list[int]:
    """Argmax tanpa harus import numpy terpisah."""
    return [max(range(len(row)), key=lambda i: row[i]) for row in matrix]


def compute_metrics(eval_pred) -> dict:
    """Metrik untuk Trainer: accuracy + macro F1."""
    from sklearn.metrics import accuracy_score, f1_score

    predictions = np_argmax(eval_pred.predictions)
    labels = eval_pred.label_ids

    return {
        "accuracy": accuracy_score(labels, predictions),
        "macro_f1": f1_score(labels, predictions, average="macro"),
    }


if __name__ == "__main__":
    raise SystemExit(main())
