# 📚 START HERE — PANDUAN BELAJAR BACKEND (Laravel) + AI Microservice

Dokumen ini peta belajarnya. Baca **dokumen ini dulu**, baru masuk ke
walkthrough-nya.

> 🎯 **Tujuan proyek:** Sistem Pelaporan Masyarakat Berbasis AI BERT
> (IndoBERT P2) — klasifikasi **12 kategori** + **4 level urgensi**, dengan
> alur **Human-in-the-Loop**: verifikasi → tindak lanjut.

---

## 1. Peta Endpoint (SEMUA SUDAH JADI ✅)

| Endpoint | Method | Akses | Status |
|---|---|---|---|
| `/api/auth/register` | POST | Publik | ✅ |
| `/api/auth/login` | POST | Publik | ✅ |
| `/api/auth/logout` | POST | Login | ✅ |
| `/api/auth/me` | GET | Login | ✅ |
| `/api/categories` | GET | Publik | ✅ |
| `/api/categories/{id}` | GET | Publik | ✅ |
| `/api/categories` | POST | Admin | ✅ |
| `/api/categories/{id}` | PUT | Admin | ✅ |
| `/api/categories/{id}` | DELETE | Admin | ✅ |
| `/api/reports/public` | GET | Publik | ✅ |
| `/api/reports/my` | GET | Login | ✅ |
| `/api/reports` | POST | citizen | ✅ |
| `/api/reports/{id}` | GET | Login | ✅ |
| `/api/admin/reports` | GET | Admin | ✅ |
| `/api/admin/reports/{id}/verify` | PATCH | Admin | ✅ |
| `/api/admin/reports/{id}/reject` | PATCH | Admin | ✅ |
| `/api/admin/reports/{id}/followup` | POST | Admin | ✅ |
| `/api/stats/summary` | GET | Login | ✅ |

**Total: 19 route API · 80 test otomatis · semua lulus.**

---

## 2. Peta File

```
app/
├── Http/Controllers/
│   ├── Controller.php              ← BASE: JSON envelope (success/message/data)
│   └── Api/
│       ├── CategoriesController.php ← CONTOH 1 (pola master data)
│       ├── ReportsController.php    ← store / my / public / show
│       ├── StatsController.php      ← 4 metrik + breakdown pie chart
│       └── Admin/
│           └── ReportController.php ← index / verify / reject / followup
├── Http/Requests/Api/             ← GERBANG: validasi + otorisasi
│   ├── ApiFormRequest.php          ← base: error 422/403 selalu enveloped
│   ├── Auth/{Register,Login}Request.php
│   ├── Category/{Store,Update}CategoryRequest.php
│   └── Report/{Store,Verify,Reject,FollowUp}ReportRequest.php
├── Http/Resources/                ← SHAPING + penyensoran data
│   ├── CategoriesResource.php
│   ├── UserResource.php
│   ├── ReportsResource.php
│   ├── ReportDetailResource.php
│   └── ReportsLogResource.php
├── Models/
│   ├── User.php          ← role, token Sanctum, registerCitizen()
│   ├── Categories.php    ← 12 kategori + kolom ai_label_id
│   ├── Reports.php       ← scopes (public/filter), status, prioritas
│   └── ReportsLog.php    ← audit trail APPEND-ONLY (NFR-04)
├── Services/
│   ├── AiBertService.php ← SATU-SATUNYA tempat yang bicara ke AI service
│   └── ReportService.php ← semua aturan bisnis (BR-01, tiket, audit trail)
├── Exceptions/
│   └── InvalidReportTransitionException.php → HTTP 409
├── Console/Commands/
│   └── CheckAiLabelsCommand.php ← php artisan ai:check-labels
└── Providers/AppServiceProvider.php ← Gate (hak akses) + RateLimiter

ai-service/               ← AI MICROSERVICE (Python/FastAPI)
├── app/{config,inference,main}.py
├── models/{category,priority}/   ← 2 model IndoBERT P2 fine-tuned
├── data/{tupoksi,urgency}/       ← 4 dataset CSV
├── tools/{train,evaluate,check_labels}.py
└── README.md

routes/api.php            ← 19 route
bootstrap/app.php         ← penyatuan error 401/403/404/409/429
config/services.php       ← kontrak ke AI service + threshold
database/{migrations,seeders,factories}/
tests/Feature/Api/        ← 80 test
docs/                     ← dokumentasi lengkap
```

---

## 3. Menjalankan

### 3.1 Setup (sekali saja)

```bash
composer install
cp .env.example .env && php artisan key:generate
```

### 3.2 Database — MySQL (KONFIGURASI AKTIF ✅)

`.env` repo ini sudah diset ke **MySQL 8.4** dan **sudah diverifikasi jalan**
(migrate + seed + 81 test + smoke test end-to-end).

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=root
DB_PASSWORD=
```

#### ⚠️ Yang sering bikin mentok: extension PHP `pdo_mysql`

MySQL server **belum cukup** — PHP harus punya driver PDO MySQL-nya.
Di Fedora, paketnya bernama **`php-mysqlnd`** (bukan `php-pdo_mysql`):

```bash
pkexec dnf install -y php-mysqlnd
php -m | grep pdo_mysql     # harus muncul: pdo_mysql
```

Di Ubuntu/Debian: `sudo apt install php-mysql`.

Kalau `php -m | grep pdo_mysql` kosong, Laravel akan error:
`could not find driver (Connection: mysql ...)`.

#### Buat database-nya

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS laravel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

#### Alternatif: SQLite (tanpa server database)

Kalau mau kerja tanpa MySQL (mis. di laptop yang ribet install server):

```env
DB_CONNECTION=sqlite
DB_DATABASE=/home/mar_coding/Coding/Lomba/WebInAction/database/database.sqlite
DB_HOST=
DB_PORT=
DB_USERNAME=
DB_PASSWORD=
```

#### Tips: jalankan test juga terhadap MySQL

`phpunit.xml` memakai SQLite in-memory supaya test cepat. Untuk memastikan
tidak ada SQL yang hanya jalan di SQLite, jalankan test terhadap MySQL:

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS laravel_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_DATABASE=laravel_test DB_USERNAME=root DB_PASSWORD= php artisan test
```

Hasil saat ini: **81/81 lulus di SQLite maupun MySQL**.

### 3.3 AI Microservice

```bash
cd ai-service
python3 -m venv .venv
./.venv/bin/pip install --index-url https://download.pytorch.org/whl/cpu torch
./.venv/bin/pip install -r requirements.txt
cp .env.example .env

# unduh 2 model (~951 MB) dari Google Drive
pip install gdown
./setup-models.sh

./.venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8100
```

> Folder `models/` tidak masuk git (`model.safetensors` 475 MB > limit GitHub
> 100 MB). Karena itu `setup-models.sh` wajib dijalankan setiap kali clone.

### 3.4 Laravel

```bash
php artisan migrate:fresh --seed     # 12 kategori + 12 laporan demo
php artisan storage:link             # supaya foto laporan bisa diakses
php artisan serve
```

### 3.5 Verifikasi

```bash
php artisan test                     # 80 test
php artisan ai:check-labels          # kontrak model <-> DB
./vendor/bin/pint --test             # style
```

### 3.6 Akun Dummy

| Peran | Email | Password |
|---|---|---|
| Warga | `warga@example.com` | `password123` |
| Admin Verifikator | `verifier@example.com` | `password123` |
| Admin Instansi | `agency@example.com` | `password123` |

---

## 4. Kontrak JSON (WAJIB BACA SEBELUM KERJA DI FRONTEND)

Semua balasan — sukses maupun gagal — punya 3 kunci utama yang sama:

```json
{
  "success": true,
  "message": "pesan yang bisa langsung ditampilkan ke user",
  "data": { },
  "errors": { },
  "meta": { }
}
```

| Kode | Kapan | `data` |
|---|---|---|
| 200 | Sukses | objek / array / `null` |
| 201 | Resource baru dibuat | objek |
| 401 | Belum login / token invalid | `null` |
| 403 | Sudah login tapi tidak punya hak | `null` |
| 404 | Data tidak ditemukan | `null` |
| 409 | Konflik (transisi status ilegal / kategori dipakai) | `null` |
| 422 | Validasi gagal | `null` + `errors` per field |
| 429 | Terlalu banyak permintaan | `null` |

`errors` hanya muncul saat 422. `meta` hanya muncul di endpoint ber-pagination.

**Di Next.js cukup satu fungsi ini untuk semua kasus:**

```ts
const res = await fetch(`${BASE}/reports`, { /* ... */ })
const json = await res.json()

if (!json.success) {
  toast.error(json.message)                    // pesan umum
  setErrors(json.errors ?? {})                 // error per field (kalau ada)
}
```

### Contoh response `POST /api/reports` (AI hidup)

```json
{
  "success": true,
  "message": "Laporan berhasil dikirim dan telah dianalisis oleh sistem AI.",
  "data": {
    "id": 14,
    "ticket_number": "LPR-2026-0014",
    "title": "Pipa gas pecah dan warga keracunan",
    "report_type": "pengaduan",
    "status": "pending",
    "status_label": "Menunggu",
    "is_anonymous": false,
    "is_private": false,
    "priority": "darurat",
    "ai_analysis": {
      "predicted_category_id": 1,
      "predicted_category_name": "Infrastruktur",
      "confidence_score": 0.677,
      "auto_disposed": false,
      "predicted_priority": "darurat",
      "priority_confidence": 0.9981
    },
    "created_at": "2026-10-21T11:28:44+00:00"
  }
}
```

---

## 5. Cheat Sheet `curl`

```bash
BASE=http://127.0.0.1:8000/api

# --- Register warga ---
curl -X POST $BASE/auth/register -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"name":"Siti Rahayu","email":"siti@example.com","password":"password123","password_confirmation":"password123"}'

# --- Login (simpan token) ---
TOKEN=$(curl -s -X POST $BASE/auth/login -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"verifier@example.com","password":"password123"}' \
  | python3 -c "import sys,json;print(json.load(sys.stdin)['data']['token'])")

# --- Kirim laporan (citizen) ---
curl -X POST $BASE/reports -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"title":"Lampu jalan mati dan kabel menjuntai","report_type":"pengaduan",
       "description":"Sudah tiga malam lampu PJU padam total dan kabel berbahaya.","location_text":"Jl. Telekomunikasi No. 12","incident_date":"2026-10-16"}'

# --- Laporan publik / milik saya / detail ---
curl "$BASE/reports/public?search=lampu&per_page=10"
curl "$BASE/reports/my" -H "Authorization: Bearer $TOKEN"
curl "$BASE/reports/1"   -H "Authorization: Bearer $TOKEN"

# --- Admin ---
curl "$BASE/admin/reports?status=pending&priority=darurat" -H "Authorization: Bearer $TOKEN"
curl -X PATCH $BASE/admin/reports/1/verify -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d '{"category_id":1,"note":"Mohon tim teknis segera menangani penerangan jalan."}'
curl -X PATCH $BASE/admin/reports/2/reject -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d '{"note":"Konten tidak menjelaskan masalah spesifik dan tidak dapat diverifikasi."}'
curl -X POST $BASE/admin/reports/3/followup -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d '{"note":"Lampu PJU sudah diganti dan kabel telah diamankan."}'

# --- Statistik ---
curl $BASE/stats/summary -H "Authorization: Bearer $TOKEN"
```

> Selalu tambahkan header `Accept: application/json`. Tanpa itu, Laravel
> mengembalikan halaman HTML error, bukan JSON.

---

## 6. Daftar Dokumen

| File | Isi |
|---|---|
| `SRS.md` | ★ **SRS versi final** — siap dilampirkan ke proposal |
| `01-arsitektur-dan-pola.md` | 8 pola Laravel yang dipakai di proyek ini |
| `02-walkthrough-categories.md` | Bedah `CategoriesController` method per method |
| `03-walkthrough-auth.md` | Bedah `AuthController` + alur token Sanctum |
| `04-latihan-tahap-berikutnya.md` | Arsip Tahap 1 (sudah selesai) |
| `05-modul-laporan.md` | Bedah Reports + Admin + Stats + semua BR |
| `06-ai-bert-dua-model.md` | Panduan AI BERT + kalibrasi threshold |
| `07-microservice-ai.md` | Justifikasi arsitektur microservice |
| `../ai-service/README.md` | Cara menjalankan service AI |

---

## 7. Checklist Kalau Mau Lanjut

- [ ] Baca `01-arsitektur-dan-pola.md`
- [ ] Integrasikan frontend ke 19 endpoint ini (pakai cheat sheet §5)
- [ ] Jalankan `php artisan ai:check-labels` sebelum demo
- [ ] Set threshold AI dari kurva kalibrasi (`ai-service/reports/*.md`)
- [ ] Tulis test untuk setiap perubahan baru
- [ ] Update `docs/SRS.md` kalau ada perubahan kontrak API
