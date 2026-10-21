"""
Konfigurasi AI Microservice.

Semua setting diambil dari environment variable supaya tidak ada yang
hard-coded. Format file .env bisa dibaca dengan python-dotenv.

Contoh (.env):
    MODEL_PATH_CATEGORY=./models/category
    MODEL_PATH_PRIORITY=./models/priority
    MAX_LEN_CATEGORY=256
    MAX_LEN_PRIORITY=128
    SERVICE_TOKEN=          # opsional, untuk proteksi antar-service
"""

import os
from dataclasses import dataclass, field
from pathlib import Path

from dotenv import load_dotenv

# Muat .env dari folder utama ai-service/
BASE_DIR = Path(__file__).resolve().parent.parent
load_dotenv(BASE_DIR / ".env")


@dataclass
class Settings:
    """Kumpulan konfigurasi service."""

    # ---------- Model ----------
    model_path_category: str = field(
        default_factory=lambda: os.getenv(
            "MODEL_PATH_CATEGORY", str(BASE_DIR / "models" / "category")
        )
    )
    model_path_priority: str = field(
        default_factory=lambda: os.getenv(
            "MODEL_PATH_PRIORITY", str(BASE_DIR / "models" / "priority")
        )
    )

    # ---------- Tokenizer ----------
    # Panjang maksimum input per model (dalam token BERT).
    #
    # Angka ini dipilih dari statistik panjang teks dataset:
    #   - model kategori : median 264 karakter, p90 624  -> 256 token cukup
    #   - model prioritas: median 102 karakter, max  162  -> 128 token cukup
    #
    # PENTING: tokenizer_config.json dari model punya model_max_length yang
    # bernilai sentinel (2^63). Kalau tidak kita set eksplisit, PyTorch akan
    # mencoba alokasi array sepanjang itu dan kehabisan memori. Karena itu
    # truncation + max_length WAJIB diset eksplisit di sini.
    max_len_category: int = field(
        default_factory=lambda: int(os.getenv("MAX_LEN_CATEGORY", "256"))
    )
    max_len_priority: int = field(
        default_factory=lambda: int(os.getenv("MAX_LEN_PRIORITY", "128"))
    )

    # ---------- Server ----------
    host: str = field(default_factory=lambda: os.getenv("HOST", "127.0.0.1"))
    port: int = field(default_factory=lambda: int(os.getenv("PORT", "8100")))

    # Token opsional untuk proteksi antar-service. Kalau diisi, request
    # harus mengirim header "X-Service-Token" dengan nilai yang sama.
    # Kalau kosong, service dianggap jaringan internal (tidak perlu auth).
    service_token: str = field(default_factory=lambda: os.getenv("SERVICE_TOKEN", ""))

    # ---------- Perilaku ----------
    # Kalau salah satu model gagal dimuat, service tetap jalan dengan model
    # yang berhasil dimuat (graceful degradation). Field yang tidak bisa
    # dihitung akan bernilai null.
    fail_soft: bool = field(
        default_factory=lambda: os.getenv("FAIL_SOFT", "true").lower() == "true"
    )


settings = Settings()
