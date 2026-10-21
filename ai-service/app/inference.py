"""
Wrapper inference untuk satu model klasifikasi BERT.

Tanggung jawab kelas ini:
  1. Memuat tokenizer + model SEKALI (bukan per request).
  2. Menjalankan prediksi untuk satu teks.
  3. Mengembalikan hasil dalam bentuk seragam:

     {
       "label_id": 0,
       "label": "Infrastruktur",
       "confidence": 0.9312
     }

Catatan performa:
  - torch.no_grad() mematikan pembuatan gradient graph -> hemat memori & waktu.
  - Batch-nya 1 teks per request (sistem kita memproses 1 laporan per request),
    tapi kodenya tetap benar kalau nanti dipakai batch.
"""

from __future__ import annotations

import time
from typing import Any

import torch
from transformers import AutoModelForSequenceClassification, AutoTokenizer


class IndoBertClassifier:
    """Klasifikasi teks ke salah satu label yang dilatih model."""

    def __init__(self, model_path: str, max_length: int = 256, device: str | None = None):
        self.model_path = model_path
        self.max_length = max_length

        # Device: pakai GPU kalau ada, kalau tidak CPU.
        if device is None:
            device = "cuda" if torch.cuda.is_available() else "cpu"
        self.device = device

        self.tokenizer = AutoTokenizer.from_pretrained(model_path)
        self.model = AutoModelForSequenceClassification.from_pretrained(model_path)
        self.model.to(self.device)
        self.model.eval()

        # id2label berasal dari config.json model. Ini adalah sumber kebenaran
        # label - aplikasi Laravel memverifikasinya lewat /health.
        raw_labels = self.model.config.id2label or {}
        self.id2label: dict[int, str] = {
            int(k): str(v) for k, v in raw_labels.items()
        }
        self.label2id: dict[str, int] = {v: k for k, v in self.id2label.items()}

    # ------------------------------------------------------------------
    @property
    def num_labels(self) -> int:
        return len(self.id2label)

    # ------------------------------------------------------------------
    def predict(self, text: str) -> dict[str, Any]:
        """
        Prediksi satu teks.

        Mengembalikan dict dengan kunci:
            label_id    : int    -> nomor label di dalam model
            label       : str    -> nama label
            confidence  : float  -> probabilitas softmax (0.0 - 1.0)
        """
        if not text or not text.strip():
            raise ValueError("Teks tidak boleh kosong.")

        started = time.perf_counter()

        inputs = self.tokenizer(
            text,
            truncation=True,        # potong teks yang melebihi max_length
            max_length=self.max_length,
            padding=False,
            return_tensors="pt",
        )
        inputs = {key: value.to(self.device) for key, value in inputs.items()}

        with torch.no_grad():
            logits = self.model(**inputs).logits

        probabilities = torch.softmax(logits, dim=-1)[0]
        confidence, index = torch.max(probabilities, dim=-1)
        label_id = int(index.item())

        return {
            "label_id": label_id,
            "label": self.id2label.get(label_id, str(label_id)),
            "confidence": round(float(confidence.item()), 4),
            "processing_ms": int((time.perf_counter() - started) * 1000),
        }

    # ------------------------------------------------------------------
    def health(self) -> dict[str, Any]:
        """Informasi model untuk endpoint /health."""
        return {
            "model_path": self.model_path,
            "device": self.device,
            "max_length": self.max_length,
            "num_labels": self.num_labels,
            "labels": dict(sorted(self.id2label.items())),
        }
