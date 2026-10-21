"""
================================================================================
CEK KECOCOKAN LABEL MODEL vs LABEL DATABASE
================================================================================
Membandingkan tiga sumber kebenaran:
  1. id2label di dalam model (config.json)
  2. label unik di dataset CSV
  3. tabel `categories` di database Laravel (via ai_label_id)

Tujuan: memastikan kontrak antar 2 service benar SEBELUM demo. Kalau labelnya
melenceng, laporan akan salah diarahkan ke instansi yang keliru.

--------------------------------------------------------------------------------
CARA PAKAI
--------------------------------------------------------------------------------
    python tools/check_labels.py \
        --model models/category \
        --dataset data/tupoksi/dataset_tupoksi_v2_train.csv \
        --label-column kategori \
        --db-categories ../database/categories_labels.json
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(BASE_DIR))

from app.inference import IndoBertClassifier  # noqa: E402


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Cek konsistensi label model.")
    parser.add_argument("--model", required=True)
    parser.add_argument("--dataset", default="")
    parser.add_argument("--label-column", default="kategori")
    parser.add_argument("--db-categories", default="", help="JSON berisi daftar kategori DB.")
    return parser.parse_args()


def normalize(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "", str(value).strip().lower())


def main() -> int:
    args = parse_args()

    print("=" * 74)
    print("CEK KONSISTENSI LABEL")
    print("=" * 74)

    classifier = IndoBertClassifier(args.model)
    model_labels = classifier.id2label
    print(f"\n1. LABEL DI MODEL ({len(model_labels)}):")
    for label_id, label in sorted(model_labels.items()):
        print(f"   {label_id:>2} -> {label}")

    problems = 0

    # ---------------- Dataset ----------------
    if args.dataset:
        import pandas as pd

        df = pd.read_csv(args.dataset)
        dataset_labels = sorted({str(x).strip() for x in df[args.label_column]})
        model_label_set = {normalize(x) for x in model_labels.values()}

        missing = [x for x in dataset_labels if normalize(x) not in model_label_set]
        extra = [x for x in model_labels.values() if normalize(x) not in {normalize(y) for y in dataset_labels}]

        print(f"\n2. LABEL DI DATASET ({len(dataset_labels)}):")
        if missing:
            problems += len(missing)
            print(f"   ! Ada di dataset tapi TIDAK ada di model: {missing}")
        else:
            print("   -> Semua label dataset ada di model.")
        if extra:
            print(f"   ! Ada di model tapi tidak dipakai di dataset: {extra}")

    # ---------------- Database ----------------
    if args.db_categories:
        db = json.loads(Path(args.db_categories).read_text(encoding="utf-8"))
        print(f"\n3. LABEL DI DATABASE ({len(db)}):")
        for item in db:
            label_id = item.get("ai_label_id")
            name = item.get("name")
            expected = model_labels.get(int(label_id)) if label_id is not None else None

            if expected is None:
                print(f"   ! id {label_id} -> \"{name}\": TIDAK ADA di model")
                problems += 1
            elif normalize(expected) != normalize(name):
                print(f"   ! id {label_id}: model=\"{expected}\" vs database=\"{name}\"")
                problems += 1
            else:
                print(f"   OK id {label_id} -> {name}")

    print(f"\n{'=' * 74}")
    if problems == 0:
        print("SEMUA KONSISTEN. Aman untuk demo.")
        return 0

    print(f"{problems} masalah ditemukan. Perbaiki sebelum demo.")
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
