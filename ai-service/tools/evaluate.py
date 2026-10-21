"""
================================================================================
EVALUASI MODEL + KALIBRASI THRESHOLD
================================================================================
Menghitung metrik evaluasi model AI pada test set, lalu mencari angka
threshold yang tepat untuk auto-disposition.

--------------------------------------------------------------------------------
KENAPA INI PENTING?
--------------------------------------------------------------------------------
SRS menetapkan ambang confidence 0.85. Tapi angka itu belum divalidasi.
Model BERT yang dilatih dengan data kecil cenderung "overconfident": ia bisa
memberikan confidence 0.99 untuk tebakan yang SALAH.

Kalau threshold dipakai tanpa validasi, akibatnya: laporan salah-kategori
otomatis diteruskan ke instansi yang salah tanpa pernah dicek manusia.

Script ini menghitung:
  - Accuracy, precision, recall, macro-F1
  - Confusion matrix per kategori
  - KURVA THRESHOLD: untuk tiap ambang, berapa % laporan yang auto-dispose
    (coverage) dan dari itu berapa % yang benar (accuracy on auto-disposed)
  - Analisis kesenjangan domain: teks berawalan "TUPOKSI:" (deskripsi
    instansi) vs keluhan organik warga

--------------------------------------------------------------------------------
CARA PAKAI
--------------------------------------------------------------------------------
    python tools/evaluate.py \
        --model models/category \
        --test data/tupoksi/dataset_tupoksi_v2_test.csv \
        --label-column kategori \
        --output reports/category_eval.md
"""

from __future__ import annotations

import argparse
import json
import sys
import time
from collections import defaultdict
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(BASE_DIR))

import pandas as pd  # noqa: E402

from app.inference import IndoBertClassifier  # noqa: E402

# Ambang kandidat untuk dievaluasi.
THRESHOLDS = [0.50, 0.60, 0.65, 0.70, 0.75, 0.80, 0.85, 0.90, 0.95, 0.99]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Evaluasi model + kalibrasi threshold.")
    parser.add_argument("--model", required=True, help="Path folder model.")
    parser.add_argument("--test", required=True, help="Path file CSV test set.")
    parser.add_argument("--text-column", default="teks", help="Nama kolom teks.")
    parser.add_argument("--label-column", default="kategori", help="Nama kolom label.")
    parser.add_argument("--max-length", type=int, default=256)
    parser.add_argument("--limit", type=int, default=0, help="0 = semua baris.")
    parser.add_argument("--output", default="", help="Path file markdown keluaran.")
    parser.add_argument("--json-output", default="", help="Path file JSON keluaran.")
    return parser.parse_args()


def normalize(label: str) -> str:
    """Normalisasi nama label supaya pencocokan tidak rapuh."""
    import re

    return re.sub(r"[^a-z0-9]+", "", str(label).strip().lower())


def main() -> int:
    args = parse_args()

    print("=" * 74)
    print("EVALUASI MODEL + KALIBRASI THRESHOLD")
    print("=" * 74)

    # ---------------- Muat data ----------------
    df = pd.read_csv(args.test)
    if args.limit:
        df = df.head(args.limit)

    print(f"Test set  : {args.test}")
    print(f"Jumlah    : {len(df)} baris")
    print(f"Kolom     : {list(df.columns)}")

    # ---------------- Muat model ----------------
    print(f"\nMemuat model: {args.model} ...")
    classifier = IndoBertClassifier(args.model, max_length=args.max_length)
    print(f"Label model ({classifier.num_labels}): {classifier.id2label}")
    print(f"Device: {classifier.device}")

    # ---------------- Inferensi ----------------
    print("\nMenjalankan inferensi...")
    started = time.perf_counter()

    predictions: list[int] = []
    confidences: list[float] = []
    latencies: list[int] = []

    for text in df[args.text_column].astype(str).tolist():
        result = classifier.predict(text)
        predictions.append(result["label_id"])
        confidences.append(result["confidence"])
        latencies.append(result["processing_ms"])

    elapsed = time.perf_counter() - started

    df = df.copy()
    df["pred_label_id"] = predictions
    df["pred_label"] = df["pred_label_id"].map(classifier.id2label)
    df["confidence"] = confidences
    df["latency_ms"] = latencies
    df["correct"] = [
        normalize(pred) == normalize(actual)
        for pred, actual in zip(df["pred_label"], df[args.label_column].astype(str))
    ]

    # ---------------- Metrik dasar ----------------
    accuracy = df["correct"].mean()
    latencies_sorted = sorted(latencies)

    print(f"\n{'=' * 74}")
    print("HASIL")
    print("=" * 74)
    print(f"Accuracy            : {accuracy * 100:.2f}%")
    print(f"Jumlah benar        : {int(df['correct'].sum())} / {len(df)}")

    per_label = df.groupby(args.label_column)["correct"].agg(["mean", "count"])
    macro_f1 = compute_macro_f1(df, args.label_column, classifier.id2label)
    print(f"Macro-F1            : {macro_f1 * 100:.2f}%")

    print(f"\nLatensi per request :")
    print(f"  rata-rata  : {sum(latencies) / len(latencies):.1f} ms")
    print(f"  median     : {latencies_sorted[len(latencies_sorted) // 2]} ms")
    print(f"  p95        : {latencies_sorted[int(len(latencies_sorted) * 0.95)]} ms")
    print(f"  throughput : {len(df) / elapsed:.1f} prediksi/detik")

    # ---------------- Analisis kesenjangan domain ----------------
    is_task = df[args.text_column].astype(str).str.strip().str.upper().str.startswith("TUPOKSI")
    print(f"\n{'=' * 74}")
    print("ANALISIS KESENJANGAN DOMAIN")
    print("=" * 74)
    if is_task.any() and (~is_task).any():
        acc_task = df.loc[is_task, "correct"].mean()
        acc_org = df.loc[~is_task, "correct"].mean()
        print(f"Teks 'TUPOKSI:' (deskripsi instansi) : {acc_task * 100:.2f}%  (n={is_task.sum()})")
        print(f"Keluhan organik warga                  : {acc_org * 100:.2f}%  (n={(~is_task).sum()})")
        print(f"Selisih                               : {abs(acc_task - acc_org) * 100:.2f} poin")
    else:
        print("Test set tidak memuat dua jenis sumber teks.")

    # ---------------- Kurva threshold ----------------
    print(f"\n{'=' * 74}")
    print("KURVA THRESHOLD (auto-disposition)")
    print("=" * 74)
    print(f"{'Threshold':>10} | {'Coverage':>9} | {'Auto':>6} | {'Benar':>7} | {'Akurasi':>9}")
    print("-" * 56)

    curve: list[dict] = []
    for threshold in THRESHOLDS:
        mask = df["confidence"] >= threshold
        total_auto = int(mask.sum())
        if total_auto == 0:
            curve.append({
                "threshold": threshold, "coverage": 0.0, "auto": 0,
                "correct": 0, "accuracy": 0.0,
            })
            print(f"{threshold:>10.2f} | {'0.00%':>9} | {0:>6} | {0:>7} | {'-':>9}")
            continue

        auto_correct = int(df.loc[mask, "correct"].sum())
        coverage = total_auto / len(df)
        auto_accuracy = auto_correct / total_auto

        curve.append({
            "threshold": threshold,
            "coverage": round(coverage, 4),
            "auto": total_auto,
            "correct": auto_correct,
            "accuracy": round(auto_accuracy, 4),
        })
        print(
            f"{threshold:>10.2f} | {coverage * 100:>8.2f}% | {total_auto:>6} | "
            f"{auto_correct:>7} | {auto_accuracy * 100:>8.2f}%"
        )

    # Rekomendasi threshold: pilih yang paling tinggi while coverage masih
    # >= 50% dan auto_accuracy >= accuracy keseluruhan (model tidak boleh
    # lebih buruk daripada tidak auto-dispose sama sekali).
    recommendation = None
    for row in curve:
        if row["auto"] > 0 and row["coverage"] >= 0.30 and row["accuracy"] >= accuracy:
            recommendation = row["threshold"]
            break

    print(f"\nRekomendasi threshold: {recommendation if recommendation else 'tidak ada (review manual)'}")
    if recommendation is not None:
        chosen = next(r for r in curve if r["threshold"] == recommendation)
        print(
            f"  -> {chosen['coverage'] * 100:.1f}% laporan auto-dispose, "
            f"akurasinya {chosen['accuracy'] * 100:.1f}% "
            f"(vs {accuracy * 100:.1f}% tanpa auto-disposition)"
        )

    # ---------------- Confusion matrix ----------------
    confusion: dict[str, dict[str, int]] = defaultdict(lambda: defaultdict(int))
    for _, row in df.iterrows():
        confusion[str(row[args.label_column])][str(row["pred_label"])] += 1

    print(f"\n{'=' * 74}")
    print("CONFUSION MATRIX (baris = label asli, kolom = prediksi)")
    print("=" * 74)
    all_labels = sorted(confusion.keys())
    header = "actual\\pred".ljust(20) + "".join(lbl[:9].rjust(11) for lbl in all_labels)
    print(header)
    for actual in all_labels:
        line = actual[:18].ljust(20)
        for predicted in all_labels:
            line += str(confusion[actual][predicted]).rjust(11)
        print(line)

    # ---------------- Simpan hasil ----------------
    report = {
        "model": args.model,
        "test_set": args.test,
        "rows": len(df),
        "accuracy": round(float(accuracy), 4),
        "macro_f1": round(float(macro_f1), 4),
        "latency_ms": {
            "mean": round(sum(latencies) / len(latencies), 2),
            "median": latencies_sorted[len(latencies_sorted) // 2],
            "p95": latencies_sorted[int(len(latencies_sorted) * 0.95)],
        },
        "domain_gap": {
            "task_definition_accuracy": round(float(df.loc[is_task, "correct"].mean()), 4)
            if is_task.any() else None,
            "organic_complaint_accuracy": round(float(df.loc[~is_task, "correct"].mean()), 4)
            if (~is_task).any() else None,
        },
        "threshold_curve": curve,
        "recommended_threshold": recommendation,
        "per_label_accuracy": {
            str(k): round(float(v["mean"]), 4) for k, v in per_label.to_dict("index").items()
        },
    }

    if args.json_output:
        Path(args.json_output).parent.mkdir(parents=True, exist_ok=True)
        Path(args.json_output).write_text(json.dumps(report, indent=2, ensure_ascii=False))
        print(f"\nJSON   -> {args.json_output}")

    if args.output:
        Path(args.output).parent.mkdir(parents=True, exist_ok=True)
        Path(args.output).write_text(to_markdown(report, confusion, all_labels), encoding="utf-8")
        print(f"Markdown-> {args.output}")

    return 0


def compute_macro_f1(df: pd.DataFrame, label_column: str, id2label: dict) -> float:
    """Hitung macro-F1 secara manual (menghindari ketergantungan sklearn)."""
    labels = sorted({str(x) for x in df[label_column]})
    f1_scores = []

    for label in labels:
        actual_positive = df[label_column].astype(str) == label
        predicted_positive = df["pred_label"].astype(str) == label

        tp = int((actual_positive & predicted_positive).sum())
        fp = int((~actual_positive & predicted_positive).sum())
        fn = int((actual_positive & ~predicted_positive).sum())

        precision = tp / (tp + fp) if (tp + fp) else 0.0
        recall = tp / (tp + fn) if (tp + fn) else 0.0
        f1 = (2 * precision * recall / (precision + recall)) if (precision + recall) else 0.0
        f1_scores.append(f1)

    return sum(f1_scores) / len(f1_scores) if f1_scores else 0.0


def to_markdown(report: dict, confusion: dict, all_labels: list[str]) -> str:
    """Ubah hasil evaluasi menjadi markdown (siap ditempel di proposal)."""
    lines = [
        "# Laporan Evaluasi Model AI",
        "",
        f"- **Model**: `{report['model']}`",
        f"- **Test set**: `{report['test_set']}` ({report['rows']} baris)",
        f"- **Accuracy**: **{report['accuracy'] * 100:.2f}%**",
        f"- **Macro-F1**: **{report['macro_f1'] * 100:.2f}%**",
        "",
        "## Latensi Inferensi",
        "",
        f"- Rata-rata: {report['latency_ms']['mean']} ms",
        f"- Median: {report['latency_ms']['median']} ms",
        f"- p95: {report['latency_ms']['p95']} ms",
        "",
        "## Kesenjangan Domain",
        "",
    ]

    gap = report["domain_gap"]
    if gap["task_definition_accuracy"] is not None:
        lines += [
            f"- Teks definisi tugas pokok instansi: **{gap['task_definition_accuracy'] * 100:.2f}%**",
            f"- Keluhan organik warga: **{gap['organic_complaint_accuracy'] * 100:.2f}%**",
            "",
        ]

    lines += [
        "## Kurva Threshold (Auto-Disposition)",
        "",
        "| Threshold | Coverage | Auto | Benar | Akurasi |",
        "| --- | --- | --- | --- | --- |",
    ]
    for row in report["threshold_curve"]:
        acc = f"{row['accuracy'] * 100:.2f}%" if row["auto"] else "-"
        lines.append(
            f"| {row['threshold']:.2f} | {row['coverage'] * 100:.2f}% | {row['auto']} | "
            f"{row['correct']} | {acc} |"
        )

    if report["recommended_threshold"]:
        lines += [
            "",
            f"**Rekomendasi threshold: `{report['recommended_threshold']}`**",
        ]

    lines += ["", "## Akurasi per Kategori", "", "| Kategori | Accuracy |", "| --- | --- |"]
    for label, acc in report["per_label_accuracy"].items():
        lines.append(f"| {label} | {acc * 100:.2f}% |")

    lines += ["", "## Confusion Matrix", ""]
    lines.append("| actual \\ pred | " + " | ".join(l[:9] for l in all_labels) + " |")
    lines.append("| --- |" + " --- |" * len(all_labels))
    for actual in all_labels:
        row = [str(confusion[actual][p]) for p in all_labels]
        lines.append(f"| {actual} | " + " | ".join(row) + " |")

    return "\n".join(lines) + "\n"


if __name__ == "__main__":
    raise SystemExit(main())
