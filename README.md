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
  Http/Controllers/Api/   ReportsController · Admin/ReportController · StatsController · CategoriesController · AgencyController
  Http/Requests/Api/      Form Request (validasi + otorisasi per endpoint)
  Http/Resources/         shaping output + penyensoran data
  Models/                 User · Categories · Reports · ReportsLog · Agency
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
php artisan test            # 98 test
php artisan ai:check-labels # cek label model <-> kategori database
```

### 7. Windows (PowerShell)

Perintah di atas ditulis untuk Linux/macOS. Di Windows, gunakan padanan berikut:

```powershell
composer install
npm install; npm run build
Copy-Item .env.example .env
php artisan key:generate

# Cek driver MySQL (PowerShell tidak punya grep)
php -m | Select-String pdo_mysql

# AI service: venv Windows ada di .venv\Scripts, bukan .venv/bin
cd ai-service
py -m venv .venv
.\.venv\Scripts\Activate.ps1
# Jika muncul "running scripts is disabled":
# Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
python -m pip install --index-url https://download.pytorch.org/whl/cpu torch
python -m pip install -r requirements.txt gdown
bash setup-models.sh          # butuh Git Bash / WSL
python -m uvicorn app.main:app --host 127.0.0.1 --port 8100

# Laravel
php artisan migrate:fresh --seed
php artisan storage:link
php artisan serve
```

Catatan khusus Windows:

- **`storage:link` gagal / permission denied.** Aktifkan *Developer Mode*
  (Settings → Privacy & security → For developers) atau jalankan PowerShell
  sebagai Administrator, lalu ulangi. Kalau tetap gagal, hapus link lama
  `public\storage` lalu buat junction:
  ```cmd
  rmdir public\storage
  mklink /J public\storage storage\app\public
  ```
- **`setup-models.sh` adalah skrip bash.** Jalankan lewat Git Bash/WSL
  (`bash setup-models.sh`), atau unduh manual:
  ```powershell
  python -m gdown --folder -O models/category 1eB8bWHMlay6nlHNE1PL8Dry28KhJ9wPF
  python -m gdown --folder -O models/priority 1o51Wh2-qdDzqqThnOetPsetjqFfWlQZO
  ```
- **Variabel environment inline gaya Linux tidak berlaku di PowerShell.**
  `DB_CONNECTION=mysql php artisan test` akan error. Pakai:
  ```powershell
  $env:DB_CONNECTION="mysql"
  $env:DB_DATABASE="laravel_test"
  $env:DB_USERNAME="root"
  $env:DB_PASSWORD=""
  php artisan test
  ```
- **Path SQLite** gunakan garis miring `/` atau escape backslash:
  `DB_DATABASE=C:/laragon/www/WebInAction/database/database.sqlite`.
- **Pint:** `./vendor/bin/pint` → `php vendor\bin\pint` (atau
  `vendor\bin\pint.bat`).
- **Perintah multi-baris** dengan `\` di dokumentasi ini harus diganti
  backtick `` ` `` di PowerShell.

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
| GET | `/api/agencies` | Publik |
| GET | `/api/agencies/{id}` | Publik |
| POST | `/api/admin/agencies` | Admin |
| POST/PUT/PATCH | `/api/admin/agencies/{id}` | Admin |
| DELETE | `/api/admin/agencies/{id}` | Admin |
| GET | `/api/reports/public` | Publik |
| GET | `/api/reports/my` | Login |
| POST | `/api/reports` | citizen |
| GET | `/api/reports/{id}` | Login |
| GET | `/api/admin/reports` | Admin |
| PATCH | `/api/admin/reports/{id}/verify` | Admin |
| PATCH | `/api/admin/reports/{id}/reject` | Admin |
| POST | `/api/admin/reports/{id}/followup` | Admin |
| GET | `/api/stats/summary` | Login (agency_admin otomatis difilter ke instansinya) |

Request memakai header `Authorization: Bearer <token>`.

## Dokumentasi

| Berkas | Isi |
|---|---|
| [docs/README.md](docs/README.md) | Peta proyek, cara jalanin, cheat sheet curl |
| [docs/SRS.md](docs/SRS.md) | Spesifikasi kebutuhan (versi final) |
| [docs/06-ai-bert-dua-model.md](docs/06-ai-bert-dua-model.md) | Panduan AI BERT, evaluasi model, kalibrasi threshold |
| [docs/07-microservice-ai.md](docs/07-microservice-ai.md) | Kenapa AI-nya dipisah jadi microservice |
| [ai-service/README.md](ai-service/README.md) | Detail service AI & tools |
