# 07 — JUSTIFIKASI ARSITEKTUR MICROSERVICE

Dokumen ini adalah bahanDefense kalau ditanya mentor/panitia:
*"Kenapa pakai microservice? Bukannya microservice itu buat tim besar?"*

---

## 1. Ringkas Jawaban

Ya, AI service di proyek ini **adalah microservice** — lebih tepatnya
**AI Microservice / Model Serving Service**.

Arsitektur keseluruhan:

```
┌─────────────────────────────────────────────────────────┐
│  CLIENT                                                │
│  Next.js (App Router) — Tailwind — Port 3000           │
└───────────────────────────┬─────────────────────────────┘
                            │ HTTPS / JSON
                            ▼
┌─────────────────────────────────────────────────────────┐
│  BACKEND — Monolith Modular (Laravel 13) — Port 8000    │
│  ┌──────────┬───────────┬───────────┬─────────────┐     │
│  │ Auth     │ Reports   │ Admin     │ Stats       │     │
│  │Sanctum   │ + AI Router│ verify/  │ 4 metrik    │     │
│  └──────────┴───────────┴───────────┴─────────────┘     │
│        │                      │                         │
│        │ Eloquent             │ POST /analyze           │
│        ▼                      ▼ (JSON, timeout 3 dtk)   │
│  ┌──────────────┐   ┌────────────────────────────────┐  │
│  │ MySQL        │   │ AI MICROSERVICE (Python) :8100  │  │
│  │ 4 tabel ERD  │   │ FastAPI · 2× IndoBERT P2        │  │
│  │(data bisnis) │   │ /analyze · /health              │  │
│  └──────────────┘   └────────────────────────────────┘  │
│   milik Laravel                milik AI Service         │
└─────────────────────────────────────────────────────────┘
```

---

## 2. Checklist 5 Kriteria Microservice

| Kriteria | Sistem ini | Bukti konkret |
|---|---|---|
| **Independently deployable** | ✅ | `php artisan serve` vs `uvicorn app.main:app`. Deployment, port, container, dependency terpisah |
| **Boundary via network** | ✅ | JSON over HTTP (`POST /analyze`), bukan function call dalam satu proses |
| **Runtime berbeda (polyglot)** | ✅ | PHP 8.3 (Laravel 13) vs Python 3 (FastAPI + PyTorch). Batas yang **tidak bisa** dilewati |
| **Scaling profile berbeda** | ✅ | AI = CPU/GPU-heavy & latency-sensitive per request. Laravel = I/O-bound ringan. Skala berbeda total |
| **Failure domain terisolasi** | ✅ | AI down → Laravel tetap jalan, laporan tetap tersimpan `pending` (FR-AI-03 kondisi 3) |

**Yang TIDAK ada:** data ownership terpisah. AI service tidak punya database —
ia hanya memegang *model artifact*. Jadi ini microservices yang **bukan**
"data-oriented" seperti Order Service / Payment Service.

Secara arsitektur: **Hybrid Monolith + Microservice** — monolith modular
(Laravel) + 1 AI microservice.

---

## 3. Justifikasi: 3 Alasan TEKNIS (bukan organisasi)

Ini yang dipakai buat jawab *"kapanOi butuh microservices? Di tim kecil,
kan?"*

> Microservice tidak selalu tepat. Tapi di kasus kita, batas antar service
> **dijustifikasi oleh 3 hal teknis**, bukan oleh kebutuhan organisasi:

### 3.1 Heterogenitas runtime yang tidak bisa dihapus

PyTorch + `transformers` = ±2 GB dependency & SciPy stack. Menaruh ini di
image PHP itu absurd; di Python itu natural. PHP juga tidak punya runtime
BERT yang layak tanpa ONNX Runtime + tetap berat.

### 3.2 Resource & scaling isolation

Inferensi butuh CPU/GPU. Laravel butuh I/O ringan. Kalau digabung dalam satu
proses, **lonjakan inferensi akan melumpuhkan API** — persis skenario yang
paling mungkin terjadi saat demo (banyak warga kirim laporan bersamaan).

### 3.3 Release cycle & versioning terpisah

Retraining model = deploy artefak 1 GB baru. Dengan batas microservice,
itu **tidak menyentuh** Laravel sama sekali: tidak perlu redeploy, tidak
risiko breaking API publik, dan versi model bisa dilacak terpisah.

### 3.4 Bonus: Fault Isolation (FR-AI-03)

Ini yang paling kuat untuk proyek kita. Kalau AI jadi fungsi internal di
Laravel, satu error = form warga ikut mati. Pemisahan service = **laporan
warga tetap masuk database** dan hanya masuk antrean verifikasi manual.

Jadi **fallback yang diwajibkan SRS (FR-AI-03 kondisi 3) HANYA mungkin
karena AI-nya microservice.** Aturan bisnis di SRS justru "membProve"
kebutuhan arsitektur microservice.

---

## 4. Istilah untuk Proposal

Hindari kalimat "pakai microservice" yang kosong. Tulis:

> *"Arsitektur sistem terdiri atas **3 komponen**: (1) frontend Next.js,
> (2) **monolith modular** Laravel REST API untuk logika bisnis dan 4 tabel
> ERD, dan (3) **AI Microservice (Model Serving Service)** berbasis Python
> FastAPI yang menyajikan dua model IndoBERT P2 fine-tuned — klasifikasi
> kategori (12 label) dan tingkat urgensi (4 level) — melalui endpoint
> inferensi sinkron `POST /analyze`."*

Istilah bonus yang strengthen nilai:

| Istilah | Arti |
|---|---|
| **Polyglot microservices** | PHP + Python dalam satu sistem |
| **Synchronous inference endpoint** | Butuh jawaban instan (bukan async job) |
| **Graceful degradation** | Degradasi halus saat service mati (FR-AI-03) |
| **Independent deployability** | Justifikasi arsitektur |
| **Failure domain isolation** | Justifikasi fallback |
| **Model serving service** | Istilah industri paling presisi |

---

## 5. Trade-off yang Jujur Harus Disebut

Tidak ada yang serba bagus. Ini yang harus siap dijawab:

| Trade-off | Realita | Mitigasi |
|---|---|---|
| **Coupling sinkron** | `POST /analyze` memblokir; AI lambat = Laravel ikut lambat | Timeout 3 detik (NFR-01) + `null` on failure → fallback |
| **Single point of failure** | 1 instance Python mati = semua request AI gagal | Sistem **tetap hidup** (cuma antrean manual). Kalau mau tahan: 2 replica di belakang nginx |
| **2 service = 2 proses** | Lebih banyak yang harus dijaga | AI service stateless + `docker compose` |
| **Over-engineering?** | Bisa dituduh | Justifikasi 3.1–3.4 di atas. Batas ini **paling murah** yang bisa dipilih given requirement AI-nya |

---

## 6. Kenapa TIDAK Split Jadi 2 Microservice (kategori & prioritas terpisah)?

Pertanyaan yang masuk akal: "Bukannya lebih baik pisahkan modelnya?"

**Tidak.** Karena:

- 2 model dalam **satu** service = **satu** deployable unit → tetap **1**
  microservice.
- 1 timeout budget (bukan 2x sequential, yang bisa jadi 6 detik).
- 1 failure surface, 1 proses untuk dimonitor, 1 container.
- Dua service Python buat 2 model =~500 MB yang sama adalah over-engineering
  untuk skala MVP.

Split baru masuk akal kalau: modelnya butuh hardware berbeda (satu GPU, satu
CPU), atau release cycle-nya benar-benar terpisah, atau timnya bertambah.

---

## 7. Deployment

### Opsi A — Satu mesin (paling sederhana, cukup untuk demo)

```bash
# Terminal 1 - AI service
cd ai-service && ./.venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8100

# Terminal 2 - Laravel
php artisan serve --port 8000
```

AI service **tidak** di-expose ke publik (bind ke 127.0.0.1). Kalau service
harus bisa diakses dari mesin lain, isi `SERVICE_TOKEN` di `.env` — request
lalu wajib mengirim header `X-Service-Token`.

### Opsi B — Docker Compose (2 container)

```yaml
services:
  ai:
    build: ./ai-service
    ports: ["8100:8100"]
    environment:
      MODEL_PATH_CATEGORY: /app/models/category
      MODEL_PATH_PRIORITY: /app/models/priority
    volumes:
      - ./ai-service/models:/app/models
  api:
    build: .
    ports: ["8000:8000"]
    environment:
      AI_BERT_URL: http://ai:8100/analyze
    depends_on: [ai]
```

### Opsi C — Terpisah (kalau butuh GPU)

Service AI di GPU host/VPS (RunPod, Railway, Colab ngrok), Laravel di
hosting biasa. Kontrak API-nya tetap sama persis — **itulah gunanya
mem formalized kontrak**.

---

## 8. Verifikasi Arsitektur

Perhatikan: **frontend Next.js tidak pernah bicara langsung ke AI service.**

```
Next.js ──► Laravel ──► AI Microservice
```

Semua lewat Laravel. Alasannya:

- Frontend tidak perlu tahu detail AI (label map, threshold, retry).
- Semua aturan bisnis (auto-disposition, BR-01, BR-02) ada di satu tempat.
- Laravel yang memastikan AI tidak dipakai untuk hal yang tidak berhak.

Kalau nanti butuh "analisis on-demand" dari frontend, tetap lewat Laravel —
tapi sebagai endpoint terpisah kalau memang perlu.

---

## 9. Ringkasan untuk Panel

> *"Sistem ini memakai arsitektur hybrid: monolith modular pada Laravel untuk
> logika bisnis, dan satu AI Microservice (model serving) berbasis Python
> FastAPI yang menyajikan dua model IndoBERT P2. Batas ini dipilih karena
> tiga alasan teknis: heterogenitas runtime (PHP vs PyTorch), perbedaan
> profil skala (I/O-bound vs GPU-bound), dan isolasi fault — yang terakhir
> justru menjadi prasyarat agar fallback FR-AI-03 bisa berjalan, yaitu agar
> laporan warga tetap tersimpan meskipun service AI mati."*
