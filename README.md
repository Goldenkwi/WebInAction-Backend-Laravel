# Sistem Pelaporan Masyarakat — Backend & AI Microservice

Backend REST API untuk Sistem Pelaporan Masyarakat Berbasis AI (IndoBERT P2).
Warga mengirim laporan bebas; AI mengklasifikasi **kategori** (12 label) dan
**tingkat prioritas** (4 level), lalu admin memverifikasi dan menindaklanjuti.

Dua service terpisah: **Laravel** (logika bisnis + database) dan **AI
Microservice** (FastAPI, menyajikan 2 model IndoBERT P2 lewat JSON/HTTP).

## Stack

| Bagian | Teknologi |
|---|---|
| Backend | Laravel 13 · PHP 8.5 · Laravel Sanctum · MySQL 8.4 |
| AI | Python 3.14 · FastAPI · PyTorch 2.14 (CPU) · Transformers 5.20 |
| Model | `indobenchmark/indobert-base-p2` (IndoBERT P2) fine-tuned × 2 |
| Frontend | Next.js 14 + Tailwind CSS (repo terpisah) |

## Struktur

```
app/
  Http/Controllers/Api/   ReportsController · Admin/ReportController · StatsController · CategoriesController
  Http/Requests/Api/      Form Request (validasi + otorisasi per endpoint)
  Http/Resources/         shaping output + penyensoran data
  Models/                 User · Categories · Reports · ReportsLog
  Services/               AiBertService (panggil AI) · ReportService (aturan bisnis)
  Exceptions/             InvalidReportTransitionException
  Console/Commands/       ai:check-labels

ai-service/               AI Microservice (Python)
  app/main.py             FastAPI: POST /analyze · GET /health
  app/inference.py        wrapper model (load sekali, predict)
  models/                 model kategori + model prioritas (diunduh, tidak masuk git)
  data/                   dataset latih/uji (diunduh, tidak masuk git)
  tools/                  train.py · evaluate.py · check_labels.py
  setup-models.sh         unduh model dari Google Drive

docs/                     dokumentasi lengkap (mulai dari docs/README.md)
```

## Menjalankan

### 1. Dependency

```bash
composer install
npm install && npm run build      # hanya butuh sekali
cp .env.example .env && php artisan key:generate
```

Extension PHP `pdo_mysql` wajib ada:

```bash
# Fedora
pkexec dnf install -y php-mysqlnd
# Ubuntu
sudo apt install php-mysql
php -m | grep pdo_mysql
```

### 2. Model AI (~951 MB)

```bash
cd ai-service
python3 -m venv .venv
./.venv/bin/pip install --index-url https://download.pytorch.org/whl/cpu torch
./.venv/bin/pip install -r requirements.txt
pip install gdown
./setup-models.sh                  # unduh 2 model dari Google Drive
```

### 3. Database

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS laravel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 4. AI Microservice (port 8100)

```bash
cd ai-service
./.venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8100
```

Dokumentasi interaktif: <http://127.0.0.1:8100/docs>

### 5. Laravel (port 8000)

```bash
php artisan migrate:fresh --seed
php artisan storage:link
php artisan serve
```

### 6. Verifikasi

```bash
php artisan test            # 81 test
php artisan ai:check-labels # cek label model <-> kategori database
```

## Akun demo

| Peran | Email | Password |
|---|---|---|
| Warga | `warga@example.com` | `password123` |
| Admin Verifikator | `verifier@example.com` | `password123` |
| Admin Instansi | `agency@example.com` | `password123` |

## Endpoint

Semua response memakai `{ success, message, data }`. `errors` muncul saat 422,
`meta` pada endpoint ber-pagination.

| Method | Endpoint | Akses |
|---|---|---|
| POST | `/api/auth/register` | Publik |
| POST | `/api/auth/login` | Publik |
| POST | `/api/auth/logout` | Login |
| GET | `/api/auth/me` | Login |
| GET | `/api/categories` | Publik |
| GET | `/api/categories/{id}` | Publik |
| POST | `/api/categories` | Admin |
| PUT | `/api/categories/{id}` | Admin |
| DELETE | `/api/categories/{id}` | Admin |
| GET | `/api/reports/public` | Publik |
| GET | `/api/reports/my` | Login |
| POST | `/api/reports` | citizen |
| GET | `/api/reports/{id}` | Login |
| GET | `/api/admin/reports` | Admin |
| PATCH | `/api/admin/reports/{id}/verify` | Admin |
| PATCH | `/api/admin/reports/{id}/reject` | Admin |
| POST | `/api/admin/reports/{id}/followup` | Admin |
| GET | `/api/stats/summary` | Login |

Request memakai header `Authorization: Bearer <token>`.

## Dokumentasi

| Berkas | Isi |
|---|---|
| [docs/README.md](docs/README.md) | Peta proyek, cara jalanin, cheat sheet curl |
| [docs/SRS.md](docs/SRS.md) | Spesifikasi kebutuhan (versi final) |
| [docs/06-ai-bert-dua-model.md](docs/06-ai-bert-dua-model.md) | Panduan AI BERT, evaluasi model, kalibrasi threshold |
| [docs/07-microservice-ai.md](docs/07-microservice-ai.md) | Kenapa AI-nya dipisah jadi microservice |
| [ai-service/README.md](ai-service/README.md) | Detail service AI & tools |
