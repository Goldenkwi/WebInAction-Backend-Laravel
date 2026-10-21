"""
================================================================================
AI MICROSERVICE  -  Model Serving Service
================================================================================
Menyajikan DUA model IndoBERT P2 fine-tuned melalui SATU endpoint inferensi:

  1. Model KATEGORI  -> 12 label (Infrastruktur, Kebersihan, Keamanan, ...)
  2. Model PRIORITAS -> 4 level  (Darurat, Tinggi, Sedang, Rendah)

Endpoint:
  POST /analyze  -> analisis teks (kategori + urgensi)
  GET  /health   -> status service + daftar label model
  GET  /         -> info singkat service

--------------------------------------------------------------------------------
KONSEP: GRACEFUL DEGRADATION
--------------------------------------------------------------------------------
Kalau salah satu model gagal dimuat, service TIDAK mati. Model yang berhasil
tetap melayani, dan field yang tidak bisa dihitung bernilai null.

Alasannya: di sisi Laravel, kegagalan AI harus "mundur" (laporan tetap
tersimpan dengan status pending), bukan "mati" (form warga error). Ini yang
membuat layanan AI bisa dipisah jadi microservice tanpa risiko ke sistem
inti. Lihat SRS FR-AI-03 kondisi 3 & NFR-05.

--------------------------------------------------------------------------------
MENJALANKAN
--------------------------------------------------------------------------------
    pip install -r requirements.txt
    uvicorn app.main:app --host 127.0.0.1 --port 8100

Dokumentasi interaktif: http://127.0.0.1:8100/docs
"""

from __future__ import annotations

import logging
from contextlib import asynccontextmanager
from typing import Any

from fastapi import FastAPI, Header, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field

from .config import settings
from .inference import IndoBertClassifier

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger("ai-service")

# Placeholder - diisi saat startup.
classifiers: dict[str, IndoBertClassifier | None] = {"category": None, "priority": None}


# ---------------------------------------------------------------------------
# SCHEMA
# ---------------------------------------------------------------------------
class AnalyzeRequest(BaseModel):
    """Body request POST /analyze."""

    text: str = Field(
        ...,
        description="Teks laporan yang akan dianalisis (judul + deskripsi digabung).",
        min_length=1,
    )


class Prediction(BaseModel):
    """Hasil prediksi satu model."""

    label_id: int | None = Field(None, description="Nomor label di dalam model.")
    label: str | None = Field(None, description="Nama label hasil prediksi.")
    confidence: float | None = Field(None, description="Probabilitas 0.0 - 1.0.")


class AnalyzeResponse(BaseModel):
    """Body response POST /analyze."""

    category: Prediction | None = Field(None, description="Prediksi kategori (12 label).")
    urgency: Prediction | None = Field(None, description="Prediksi urgensi (4 level).")
    processing_ms: int = Field(..., description="Total waktu proses dalam milidetik.")


# ---------------------------------------------------------------------------
# STARTUP / SHUTDOWN
# ---------------------------------------------------------------------------
@asynccontextmanager
async def lifespan(app: FastAPI):
    """Memuat model saat service start, melepas saat shutdown."""
    logger.info("Memuat model AI...")

    # Model kategori (wajib).
    try:
        classifiers["category"] = IndoBertClassifier(
            settings.model_path_category, max_length=settings.max_len_category
        )
        logger.info(
            "Model kategori siap: %s label, device=%s",
            classifiers["category"].num_labels,
            classifiers["category"].device,
        )
    except Exception as exc:  # noqa: BLE001
        logger.error("GAGAL memuat model kategori: %s", exc)
        if not settings.fail_soft:
            raise

    # Model prioritas (opsional - degrade kalau gagal).
    try:
        classifiers["priority"] = IndoBertClassifier(
            settings.model_path_priority, max_length=settings.max_len_priority
        )
        logger.info(
            "Model prioritas siap: %s label, device=%s",
            classifiers["priority"].num_labels,
            classifiers["priority"].device,
        )
    except Exception as exc:  # noqa: BLE001
        logger.warning("GAGAL memuat model prioritas (layanan tetap jalan): %s", exc)
        classifiers["priority"] = None

    # Warm-up: jalankan 1 inferensi dummy supaya request pertama user
    # tidak ikut menanggung biaya "model baru dimuat".
    for name, model in classifiers.items():
        if model is None:
            continue
        try:
            model.predict("warm up")
            logger.info("Warm-up model %s selesai.", name)
        except Exception as exc:  # noqa: BLE001
            logger.warning("Warm-up model %s gagal: %s", name, exc)

    logger.info("AI Microservice siap menerima request.")
    yield

    logger.info("Melepas model AI...")
    classifiers["category"] = None
    classifiers["priority"] = None


app = FastAPI(
    title="AI Microservice - IndoBERT P2 Complaint Routing",
    description=(
        "Model serving service untuk klasifikasi kategori (12 label) dan "
        "tingkat urgensi (4 level) pada laporan masyarakat."
    ),
    version="1.0.0",
    lifespan=lifespan,
)

# CORS_longgar untuk keperluan pengembangan lokal.
# Di produksi, service ini idealnya hanya menerima request dari Laravel
# di jaringan internal - tidak perlu expose ke publik.
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


# ---------------------------------------------------------------------------
# ENDPOINTS
# ---------------------------------------------------------------------------
@app.get("/")
def root() -> dict[str, Any]:
    """Informasi singkat service."""
    return {
        "service": "ai-service",
        "version": app.version,
        "models_loaded": {
            "category": classifiers["category"] is not None,
            "priority": classifiers["priority"] is not None,
        },
        "endpoints": {"analyze": "POST /analyze", "health": "GET /health"},
    }


@app.get("/health")
def health() -> dict[str, Any]:
    """
    Status service + daftar label tiap model.

    Endpoint ini dipakai command Laravel `php artisan ai:check-labels` untuk
    memverifikasi bahwa label di model cocok dengan kategori di database.
    """
    def model_info(model: IndoBertClassifier | None) -> dict[str, Any]:
        if model is None:
            return {"loaded": False, "labels": {}}
        info = model.health()
        info["loaded"] = True
        return info

    return {
        "status": "ok",
        "models": {
            "category": model_info(classifiers["category"]),
            "priority": model_info(classifiers["priority"]),
        },
        # Bentuk ringkas yang dipakai command ai:check-labels.
        "labels": {
            "category": (
                classifiers["category"].id2label if classifiers["category"] else {}
            ),
            "priority": (
                classifiers["priority"].id2label if classifiers["priority"] else {}
            ),
        },
    }


@app.post("/analyze", response_model=AnalyzeResponse)
def analyze(
    payload: AnalyzeRequest,
    x_service_token: str | None = Header(default=None, alias="X-Service-Token"),
) -> AnalyzeResponse:
    """
    Analisis satu teks: kategori + urgensi.

    Mengembalikan null untuk bagian yang tidak bisa dihitung (mis. model
    prioritas gagal dimuat). Laravel akan menandai laporan sebagai "belum
    dianalisis AI" dan menaruhnya di antrean verifikasi manual.
    """
    # Proteksi antar-service (opsional).
    if settings.service_token and x_service_token != settings.service_token:
        raise HTTPException(status_code=401, detail="Token service tidak valid.")

    if classifiers["category"] is None and classifiers["priority"] is None:
        raise HTTPException(
            status_code=503,
            detail="Tidak ada model yang berhasil dimuat.",
        )

    import time

    started = time.perf_counter()
    text = payload.text.strip()

    category_result: Prediction | None = None
    urgency_result: Prediction | None = None

    if classifiers["category"] is not None:
        try:
            raw = classifiers["category"].predict(text)
            category_result = Prediction(
                label_id=raw["label_id"],
                label=raw["label"],
                confidence=raw["confidence"],
            )
        except Exception as exc:  # noqa: BLE001
            logger.error("Prediksi kategori gagal: %s", exc)

    if classifiers["priority"] is not None:
        try:
            raw = classifiers["priority"].predict(text)
            urgency_result = Prediction(
                label_id=raw["label_id"],
                label=raw["label"],
                confidence=raw["confidence"],
            )
        except Exception as exc:  # noqa: BLE001
            logger.error("Prediksi urgensi gagal: %s", exc)

    return AnalyzeResponse(
        category=category_result,
        urgency=urgency_result,
        processing_ms=int((time.perf_counter() - started) * 1000),
    )
