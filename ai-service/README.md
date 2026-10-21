# 🤖 AI MICROSERVICE — Model Serving Service (IndoBERT P2 ×2)

Service Python/FastAPI yang menyajikan **dua model IndoBERT P2 fine-tuned**
untuk Sistem Pelaporan Masyarakat:

| Model | Tugas | Label | Path |
|---|---|---|---|
| **Kategori** | Menentukan laporan ini masuk instansi mana | 12 | `models/category/` |
| **Prioritas** | Menentukan seberapa penting laporan ini | 4 | `models/priority/` |

Arsitektur & justifikasinya: `../docs/07-microservice-ai.md`
Panduan lengkap + kalibrasi threshold: `../docs/06-ai-bert-dua-model.md`

---

## ⚡ Quick Start

```bash
cd ai-service

# 1. Dependency (venv + torch CPU)
python3 -m venv .venv
./.venv/bin/pip install --index-url https://download.pytorch.org/whl/cpu torch
./.venv/bin/pip install -r requirements.txt

# 2. Konfigurasi
cp .env.example .env

# 3. Jalankan
./.venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8100
```

Dokumentasi interaktif: <http://127.0.0.1:8100/docs>

> **Penting:** pakai `--index-url https://download.pytorch.org/whl/cpu` untuk
> torch. Wheel default di Linux menarik CUDA toolkit (~3–5 GB) yang tidak
> diperlukan untuk inferensi CPU.

### Unduh model (sekali saja)

```bash
cd ai-service
pip install gdown
./setup-models.sh
```

Script ini mengunduh 2 model (~951 MB) dari Google Drive ke `models/category/`
dan `models/priority/`. Folder model **tidak** masuk git (udah di-`.gitignore`)
karena `model.safetensors` berukuran 475 MB — GitHub menolak file >100 MB.

Kalau mau unduh manual:

```bash
gdown --folder -O models/category 1eB8bWHMlay6nlHNE1PL8Dry28KhJ9wPF   # model kategori
gdown --folder -O models/priority 1o51Wh2-qdDzqqThnOetPsetjqFfWlQZO   # model prioritas
```

> Harus pakai `gdown`. File >100 MB dari Google Drive butuh token
> "virus scan confirm" — `curl` biasa akan menerima halaman HTML, bukan file.

### Cek service jalan

```bash
curl http://127.0.0.1:8100/health
```

```json
{
  "status": "ok",
  "labels": {
    "category": {"0": "Infrastruktur", "1": "Kebersihan", "...": "..."},
    "priority": {"0": "Darurat", "1": "Tinggi", "2": "Sedang", "3": "Rendah"}
  }
}
```

### Cek kontrak dengan database

```bash
cd .. && php artisan ai:check-labels
```

```
  Label model   : 12 kategori
  Label database: 12 kategori
  Cocok         : 12
  Tidak cocok   : 0
  -> KONTRAK SESUAI, aman untuk demo.
```

---

## 📡 API

### `POST /analyze`

```bash
curl -X POST http://127.0.0.1:8100/analyze \
  -H "Content-Type: application/json" \
  -d '{"text":"lampu jalan mati dan kabel menjuntai di dekat sekolah"}'
```

```json
{
  "category": { "label_id": 0, "label": "Infrastruktur", "confidence": 0.9362 },
  "urgency":  { "label_id": 2, "label": "Sedang",         "confidence": 0.9901 },
  "processing_ms": 308
}
```

Kalau salah satu model gagal → field-nya `null`, sisanya tetap jalan
(**graceful degradation**).

### `GET /health`

Status service + daftar label tiap model. Dipakai `php artisan ai:check-labels`.

### `GET /`

Info singkat service.

---

## 📁 Struktur

```
ai-service/
├── app/
│   ├── __init__.py
│   ├── config.py          # semua setting via env
│   ├── inference.py       # wrapper model (load sekali, predict)
│   └── main.py            # FastAPI: /analyze, /health, /
├── models/
│   ├── category/          # config.json, model.safetensors, tokenizer.*
│   └── priority/          # config.json, model.safetensors, tokenizer.*
├── data/
│   ├── tupoksi/           # dataset_tupoksi_v2_{train,test}.csv
│   └── urgency/           # dataset_urgency_v2_{train,test}.csv
├── setup-models.sh        # unduh 2 model dari Google Drive
├── tools/
│   ├── train.py           # fine-tune ulang (Colab-ready)
│   ├── evaluate.py        # akurasi + confusion matrix + kurva threshold
│   └── check_labels.py    # cek konsistensi label
├── reports/               # hasil evaluasi (dibuat otomatis)
├── requirements.txt
└── .env.example
```

---

## ⚙️ Konfigurasi (`.env`)

| Variabel | Default | Keterangan |
|---|---|---|
| `MODEL_PATH_CATEGORY` | `./models/category` | Path model kategori |
| `MODEL_PATH_PRIORITY` | `./models/priority` | Path model prioritas |
| `MAX_LEN_CATEGORY` | `256` | Panjang maks input (token) — **wajib eksplisit** |
| `MAX_LEN_PRIORITY` | `128` | idem |
| `HOST` / `PORT` | `127.0.0.1` / `8100` | Alamat bind service |
| `SERVICE_TOKEN` | *(kosong)* | Kalau diisi, request wajib header `X-Service-Token` |
| `FAIL_SOFT` | `true` | Service tetap jalan kalau salah satu model gagal dimuat |

> **Kenapa `MAX_LEN` wajib di-set?** `tokenizer_config.json` hasil training
> memuat `model_max_length` bernilai sentinel (`1000000000000000019884624838656`).
> Tanpa truncation + max_length eksplisit, PyTorch akan mencoba alokasi array
> sepanjang itu → kehabisan memori.

---

## 🔬 Evaluasi & Kalibrasi Threshold

```bash
# Model kategori
./.venv/bin/python tools/evaluate.py \
  --model models/category \
  --test data/tupoksi/dataset_tupoksi_v2_test.csv \
  --label-column kategori \
  --output reports/category_eval.md \
  --json-output reports/category_eval.json

# Model prioritas
./.venv/bin/python tools/evaluate.py \
  --model models/priority \
  --test data/urgency/dataset_urgency_v2_test.csv \
  --label-column level \
  --output reports/priority_eval.md \
  --json-output reports/priority_eval.json
```

Output-nya berisi accuracy, macro-F1, confusion matrix, **kurva threshold**
(coverage vs accuracy), dan analisis kesenjangan domain.

**Gunakan hasilnya untuk menentukan `AI_BERT_CATEGORY_THRESHOLD` & `AI_BERT_PRIORITY_THRESHOLD`
di `.env` Laravel** — jangan asal pakai 0.85.

---

## 🔁 Retrain (opsional)

```bash
# Google Colab (GPU gratis)
!pip install -q -r requirements.txt
!python tools/train.py \
    --model indobenchmark/indobert-base-p2 \
    --train data/tupoksi/dataset_tupoksi_v2_train.csv \
    --test  data/tupoksi/dataset_tupoksi_v2_test.csv \
    --label-column kategori \
    --output models/category \
    --epochs 3
```

Untuk **menutup kesenjangan domain** (65% data kategori berawalan `TUPOKSI:`
yaitu deskripsi instansi, bukan keluhan warga), tambahkan baris keluhan warga
realistis ke CSV latih dengan kolom yang sama, lalu latih ulang.

---

## 🛡️ Catatan Operasional

**1. Jangan expose service ini ke publik.**
Default-nya bind ke `127.0.0.1` — hanya bisa diakses dari mesin yang sama.
Kalau harus diakses dari host lain:
- isi `SERVICE_TOKEN` di `.env` (header `X-Service-Token`), atau
- taruh di jaringan internal / reverse proxy dengan TLS.

**2. Graceful degradation adalah fitur, bukan bug.**
Kalau model gagal dimuat, service tetap jalan dengan model yang berhasil.
Di sisi Laravel, kegagalan AI tidak pernah menggagalkan form warga
(FR-AI-03 kondisi 3 / NFR-05). **JANGAN ubah ini jadi `raise`** tanpa
memahami konsekuensinya.

**3. Timeout 3 detik itu di sisi Laravel.**
`config/services.php` → `ai_bert.timeout` = 3. Service AI sendiri tidak
membatasi waktu; yang membatasi adalah kliennya. Kalau kamu mengubahnya,
ubah juga `NFR-01` di SRS.

**4. Monitoring sederhana.**
```bash
tail -f /tmp/ai_service.log          # log service
curl -s localhost:8100/health | jq .  # cek status
```
Cek juga `storage/logs/laravel.log` untuk pesan
`"AI Bert service tidak dapat dihubungi."` — itu indikator AI mati.

---

## 📦 Ukuran

| Item | Ukuran |
|---|---|
| `models/category/model.safetensors` | 498 MB |
| `models/priority/model.safetensors` | 498 MB |
| `data/*.csv` | ~5 MB |
| `.venv/` (torch CPU) | ~900 MB |
| **Total** | **~1,9 GB** |

Folder `models/`, `data/`, dan `.venv/` **tidak** masuk git (lihat `.gitignore`).
