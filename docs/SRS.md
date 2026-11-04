# 📄 SRS — SISTEM PELAPORAN MASYARAKAT BERBASIS AI BERT
### Versi Final (2.0) — Revisi setelah implementasi

> Dokumen ini adalah versi FINAL dari SRS, sudah **konsisten dengan kode yang
> terimplementasi**. Lampirkan ke proposal/laporan kompetisi.
>
> Perbedaan dari SRS v1.0 dicatat di [§12 Revisi](#12-revisi-dari-srs-v10).

---

## 1. Deskripsi Umum

Sistem mengotomatisasi pembacaan laporan teks bebas dari masyarakat
menggunakan **IndoBERT P2** (Transformer/BERT Encoding), memetakan laporan ke
kategori masalah, menilai tingkat urgensi, dan mendisposisikannya ke pihak
berwenang dengan mekanisme **Human-in-the-Loop**.

### Arsitektur

```
Next.js (Frontend) ──HTTP/JSON──► Laravel REST API ──HTTP/JSON──► AI Microservice (Python/FastAPI)
                                          │                                    │
                                          ▼                                    ▼
                                    MySQL 8.4 (4 tabel)              2× model IndoBERT P2
```

**Stack:** Laravel 13 (PHP 8.5) · Laravel Sanctum · MySQL 8.4 (utf8mb4) ·
Python 3.14 + FastAPI + PyTorch 2.14 (CPU) + Transformers 5.20 ·
IndoBERT P2 (indobenchmark/indobert-base-p2, 124,5M parameter) ·
Next.js 14 + Tailwind CSS.

> **Catatan deployment:** extension PHP `pdo_mysql` wajib ada. Di Fedora
> paketnya `php-mysqlnd`; di Ubuntu `php-mysql`. Tanpa itu Laravel gagal
> dengan `could not find driver`.

---

## 2. Karakteristik Aktor

| Aktor | `role` | Hak Akses |
|---|---|---|
| **Masyarakat (Pelapor)** | `citizen` | Kirim laporan (publik/privat/anonim), lihat daftar laporan publik, pantau timeline laporannya |
| **Admin Verifikator** | `verifier_admin` | Pantau laporan masuk, tinjau tebakan AI + confidence, verifikasi (kategori + memo) atau tolak (alasan) |
| **Admin Instansi / Unit** | `agency_admin` | Terima laporan terverifikasi, eksekusi penanganan, tindak lanjuti (feedback + foto bukti) |
| **Modul AI BERT** | *Service eksternal* | Preprocessing teks → prediksi `category_id` (12 label) + `urgency` (4 level) + confidence score |

> **Tips efisiensi tim 2 orang:** `verifier_admin` & `agency_admin` dapat
> digabung ke satu akun `admin`, **tetapi** alur tombol **Verifikasi → Tindak
> Lanjut** wajib tetap ada agar timeline sesuai referensi Aspirasi ITB.
> Implementasi: gate `verify-reports`, `reject-reports`, `followup-reports`
> mengizinkan kedua role (lihat `app/Providers/AppServiceProvider.php`).

---

## 3. Kebutuhan Fungsional

### A. Modul Autentikasi & Identitas

| Kode | Kebutuhan | Endpoint |
|---|---|---|
| **FR-AUTH-01** | Autentikasi Email & Password (Laravel Sanctum, Bearer Token) | `POST /api/auth/register`, `POST /api/auth/login` |
| **FR-AUTH-02** | PengarahAN otomatis ke dashboard sesuai `role` | Data `role` dikembalikan di response login/register |
| **FR-AUTH-03** | Sesi aktif bisa dihapus | `POST /api/auth/logout` |
| **FR-AUTH-04** | Pemeriksaan sesi masih valid | `GET /api/auth/me` |

### B. Modul Pelaporan Masyarakat

| Kode | Kebutuhan | Endpoint |
|---|---|---|
| **FR-REP-01** | Formulir: `title`, `report_type` (`pengaduan`/`aspirasi`), `category_id` (opsional), `incident_date` (**wajib iff pengaduan**), `description`, `location_text`, `image` (opsional) | `POST /api/reports` |
| **FR-REP-02** | Toggle independen `is_anonymous` & `is_private` | field yang sama |
| **FR-REP-03** | Nomor tiket otomatis `LPR-YYYY-XXXX` | field `ticket_number` |
| **FR-REP-04** | Warga melihat & memantau laporannya sendiri | `GET /api/reports/my` |
| **FR-REP-05** | Daftar laporan publik + filter | `GET /api/reports/public` |
| **FR-REP-06** | Detail laporan + timeline 3 tahap | `GET /api/reports/{id}` → `timeline_logs` |

### C. Modul Analisis Teks AI & Disposisi Otomatis

| Kode | Kebutuhan | Implementasi |
|---|---|---|
| **FR-AI-01** | Laravel menggabungkan `title` + `description`, mengirim ke AI Microservice | `ReportService::createFromCitizen()` |
| **FR-AI-02** | Menyimpan hasil AI: `ai_pred_cat_id`, `ai_confidence`, `ai_priority`, `ai_priority_confidence` | tabel `reports` |
| **FR-AI-03a** | Confidence ≥ threshold → `category_id` terisi otomatis (status tetap `pending`) | `ReportService` |
| **FR-AI-03b** | Confidence < threshold → masuk antrean verifikasi manual | badge "Perlu Verifikasi Manual" |
| **FR-AI-03c** | **Fallback**: AI down/timeout → laporan tetap tersimpan `status = pending` | `AiBertService` return `null` |
| **FR-AI-04** | **Prioritas/urgensi** 4 level (Darurat/Tinggi/Sedang/Rendah) dari model kedua | field `ai_priority` & `priority` |
| **FR-AI-05** | Verifikasi kontrak label model ↔ database | `php artisan ai:check-labels` |

### D. Modul Verifikasi & Tindak Lanjut Admin

| Kode | Kebutuhan | Endpoint |
|---|---|---|
| **FR-ADM-01** | Verifikasi: pilih `category_id` final + isi **Memo Penugasan** → `verified` | `PATCH /api/admin/reports/{id}/verify` |
| **FR-ADM-02** | Tolak: isi **Alasan Penolakan** → `rejected` | `PATCH /api/admin/reports/{id}/reject` |
| **FR-ADM-03** | Koreksi admin terhadap tebakan AI tetap tersimpan terpisah | `category_id` ≠ `ai_pred_cat_id` |
| **FR-ADM-04** | Tindak lanjut: isi **Feedback** + upload `proof_img` → `followed_up` | `POST /api/admin/reports/{id}/followup` |
| **FR-ADM-05** | Tabel laporan admin + filter per status/kategori/prioritas | `GET /api/admin/reports` |

### E. Modul Eksplorasi, Riwayat & Statistik

| Kode | Kebutuhan | Endpoint |
|---|---|---|
| **FR-PUB-01** | Filter: `search`, `category_id`, `status`, `priority`, `start_date`, `end_date` | semua endpoint daftar |
| **FR-PUB-02** | Timeline 3 tahap + riwayat pesan | `timeline_logs` di `GET /api/reports/{id}` |
| **FR-STAT-01** | 4 metrik: **Rata-rata Waktu Penyelesaian (Hari)**, **Total Laporan**, **Laporan Masuk (Menunggu)**, **Laporan Selesai (Ditindaklanjuti)** | `GET /api/stats/summary` |
| **FR-STAT-02** | Breakdown per status / kategori / prioritas (untuk Pie Chart) | field `by_status`, `by_category`, `by_priority` |
| **FR-STAT-03** | Dashboard `agency_admin` hanya menghitung laporan yang ditugaskan ke instansinya; `verifier_admin` tetap global | filter `assigned_agency_id` di `GET /api/stats/summary` |

---

## 4. Kebutuhan Non-Fungsional

| Kode | Aspek | Spesifikasi | Implementasi |
|---|---|---|---|
| **NFR-01** | Performa AI | Request Laravel → AI maks **3 detik** | `Http::timeout(3)` di `AiBertService` |
| **NFR-02** | Keamanan data | Password di-hash bcrypt; identitas pelapor disensor jika `is_anonymous` (kecuali pelapor sendiri & admin) | cast `hashed`, `UserResource`, `ReportsResource` |
| **NFR-03** | Validasi file | `jpg, jpeg, png, webp`, maks **2 MB** | aturan `mimes` + `max:2048` |
| **NFR-04** | Auditability | `reports_logs` **append-only** (hanya INSERT) | event `updating`/`deleting` di model `ReportsLog` melempar exception |
| **NFR-05** | Ketahanan layanan | Gangguan AI tidak boleh menggagalkan pengiriman laporan | graceful degradation di 2 sisi (Laravel + Python) |
| **NFR-06** | Kualitas kode | Type-safe, terformat, ter-test | 98 test otomatis, Laravel Pint |

---

## 5. Aturan Bisnis

| Kode | Aturan | Ditegakkan di |
|---|---|---|
| **BR-01** | Transisi status satu arah: `pending → verified → followed_up`; `pending → rejected`. Setelah `rejected`/`followed_up` = final | `ReportService::assertTransition()` → HTTP 409 |
| **BR-02** | `note` wajib untuk `verified`, `rejected`, `followed_up` | Form Request (`required`) → HTTP 422 |
| **BR-03** | Laporan publik hanya: `is_private = false` **DAN** status `verified`/`followed_up` | `Reports::scopePublic()` |
| **BR-04** | Pelapor hanya melihat laporannya sendiri; admin melihat semua | scope query + `ReportsController::show()` |
| **BR-05** | `ai_pred_cat_id` & `ai_priority` tidak boleh diubah admin (data evaluasi) | tidak ada di endpoint admin |
| **BR-06** | Role dan `is_anonymous` tidak boleh datang dari input client | `role` tidak ada di `$fillable` |

---

## 6. ERD — 4 Tabel

### `users`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | BIGINT PK | |
| `name` | VARCHAR | |
| `email` | VARCHAR UNIQUE | |
| `password` | VARCHAR | bcrypt hash |
| `role` | ENUM | `citizen` \| `verifier_admin` \| `agency_admin` (default `citizen`) |
| `remember_token`, `timestamps` | | |

### `categories`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | BIGINT PK | |
| `name` | VARCHAR UNIQUE | **Persis sama** dengan `id2label` model |
| `icon` | VARCHAR NULL | emoji |
| `ai_label_id` | TINYINT UNIQUE NULL | **Pemetaan ke label model (0–11)** |
| `timestamps` | | |

### `reports`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | BIGINT PK | |
| `ticket_number` | VARCHAR UNIQUE | `LPR-YYYY-XXXX` |
| `user_id` | FK → users | pelapor |
| `category_id` | FK → categories NULL | **disposisi** (bisa diubah admin) |
| `ai_pred_cat_id` | FK → categories NULL | **tebakan AI** (tidak diubah) |
| `ai_confidence` | FLOAT NULL | 0.00–1.00 |
| `ai_priority` | ENUM NULL | `rendah\|sedang\|tinggi\|darurat` (tebakan AI) |
| `ai_priority_confidence` | FLOAT NULL | 0.00–1.00 |
| `priority` | ENUM NULL | **efektif** (bisa dikoreksi admin) |
| `report_type` | ENUM | `pengaduan` \| `aspirasi` |
| `title` | VARCHAR(150) | |
| `description` | TEXT | |
| `incident_date` | DATE NULL | wajib iff pengaduan |
| `image_url` | VARCHAR NULL | |
| `location_text` | VARCHAR(255) | |
| `is_anonymous` | BOOLEAN | default false |
| `is_private` | BOOLEAN | default false |
| `status` | ENUM | `pending` \| `verified` \| `followed_up` \| `rejected` (default `pending`) |
| `timestamps` | | index `status`, `created_at`, `priority` |

### `reports_logs` (append-only)
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | BIGINT PK | |
| `report_id` | FK → reports | cascade delete |
| `changed_by` | FK → users NULL | NULL = sistem AI |
| `status` | ENUM | status baru |
| `note` | TEXT NULL | memo / alasan / feedback |
| `proof_img` | VARCHAR NULL | foto bukti |
| `timestamps` | | index `(report_id, created_at)` |

> **Catatan:** nama tabel di implementasi adalah `reports_logs` (mengikuti
> class `ReportsLog`), sedangkan di ERD v1 ditulis `report_logs`. Hanya
> konvensi penamaan.

---

## 7. Kontrak REST API

Semua response memakai envelope: `{ success, message, data }` (+ `errors`
untuk 422, + `meta` untuk pagination).

| Method | Endpoint | Akses | Fungsi |
|---|---|---|---|
| `POST` | `/api/auth/register` | Publik | Daftar akun warga |
| `POST` | `/api/auth/login` | Publik | Login → `token` + `user` |
| `POST` | `/api/auth/logout` | Login | Hapus token sesi ini |
| `GET` | `/api/auth/me` | Login | Profil user aktif |
| `GET` | `/api/categories` | Publik | Daftar 12 kategori (+`?search=`, `?with_counts=`) |
| `GET` | `/api/categories/{id}` | Publik | Detail kategori |
| `POST` | `/api/categories` | Admin | Tambah kategori |
| `PUT` | `/api/categories/{id}` | Admin | Ubah kategori |
| `DELETE` | `/api/categories/{id}` | Admin | Hapus kategori (409 jika dipakai laporan) |
| `GET` | `/api/agencies` | Publik | Daftar instansi aktif (+`?search=`, `?active_only=0`) |
| `GET` | `/api/agencies/{id}` | Publik | Detail instansi + kategori + statistik |
| `POST` | `/api/admin/agencies` | Admin | Tambah instansi (+upload `logo`) |
| `POST/PUT/PATCH` | `/api/admin/agencies/{id}` | Admin | Ubah instansi / ganti logo |
| `DELETE` | `/api/admin/agencies/{id}` | Admin | Hapus instansi (409 jika masih dipakai) |
| `GET` | `/api/reports/public` | Publik | Laporan publik (BR-03) + filter |
| `GET` | `/api/reports/my` | Login | Laporan milik sendiri |
| `POST` | `/api/reports` | citizen | Buat laporan → AI → simpan + log |
| `GET` | `/api/reports/{id}` | Login | Detail + `timeline_logs` |
| `GET` | `/api/admin/reports` | Admin | Tabel laporan admin + filter |
| `PATCH` | `/api/admin/reports/{id}/verify` | Admin | Verifikasi + memo → `verified` |
| `PATCH` | `/api/admin/reports/{id}/reject` | Admin | Tolak + alasan → `rejected` |
| `POST` | `/api/admin/reports/{id}/followup` | Admin | Tindak lanjut + bukti → `followed_up` |
| `GET` | `/api/stats/summary` | Login | 4 metrik + breakdown (agency_admin difilter ke instansinya) |

### Kontrak AI Microservice (internal)

```http
POST /analyze   {"text": "..."}
→ {"category":{"label_id":0,"label":"Infrastruktur","confidence":0.93},
   "urgency":{"label_id":2,"label":"Sedang","confidence":0.81},
   "processing_ms":74}
GET  /health    → {"labels":{"category":{...},"priority":{...}}}
```

---

## 8. Model AI

| Aspek | Kategori | Prioritas |
|---|---|---|
| Base model | `indobenchmark/indobert-base-p2` | sama |
| Head | `BertForSequenceClassification` | sama |
| Label | 12 | 4 |
| Data latih | 9.600 baris | 4.800 baris |
| Data uji | 2.400 baris | 1.600 baris |
| Split | balanced, **0% leakage** | balanced, **0% leakage** |
| `max_length` | 256 token | 128 token |
| **Accuracy (test set)** | **99,88%** | **98,31%** |
| **Macro-F1** | **99,87%** | **98,31%** |
| Latensi (mean / p95) | 265,7 ms / 481 ms | 144,0 ms / 189 ms |
| Threshold dipakai | 0,85 | 0,85 |
| Coverage auto-dispose | 99,67% | 96,81% |
| Akurasi auto-dispose | 99,96% | 99,29% |

**Kesenjangan domain** (model kategori): 100,00% pada teks definisi tugas
pokok instansi vs 99,66% pada keluhan organik warga — selisih hanya **0,34
poin**. Model generalisasi baik antar dua jenis sumber teks tersebut.

**Latensi gabungan kedua model: ±410 ms** (p95 ±670 ms), memenuhi **NFR-01**
(batas 3 detik) dengan margin 4,5×.

> ⚠️ **Catatan interpretasi:** test set berada pada distribusi yang sama dengan
> data latih (keduanya sintetis). Pada teks warga asli, confidence model
> menurun signifikan (teramati 0,68 pada contoh laporan riil) sehingga ambang
> 0,85 bersifat **konservatif** — Human-in-the-Loop tetap efektif. Pengukuran
> akurasi pada domain nyata (±50–100 laporan warga berlabel manual) adalah
> pekerjaan lanjutan yang akan memperkaya bab evaluasi proposal.

Lihat `docs/06-ai-bert-dua-model.md` untuk hasil evaluasi & kalibrasi
threshold lengkap.

---

## 9. Arsitektur Microservice

AI service adalah **AI Microservice (Model Serving Service)** — justified oleh
3 alasan teknis: heterogenitas runtime (PHP vs PyTorch), perbedaan profil
scaling (I/O-bound vs GPU-bound), dan **fault isolation** yang menjadi
prasyarat fallback FR-AI-03. Detail: `docs/07-microservice-ai.md`.

---

## 10. Pengujian

| Jenis | Jumlah | Cakupan |
|---|---|---|
| Feature test (Auth) | 10 | register, login, hash, role escalation, token invalidation |
| Feature test (Kategori) | 11 | CRUD, role guard, unique, guard 409 |
| Feature test (Laporan) | 31 | submit, validasi, upload, AI fallback, privasi, anonim |
| Feature test (Admin) | 25 | verify/reject/followup, BR-01, BR-02, koreksi AI, disposisi instansi |
| Feature test (Stats) | 10 | 4 metrik, rata-rata hari, breakdown, scoping agency_admin |
| Feature test (Instansi) | 9 | CRUD, logo, otorisasi, guard 409 |
| Contoh bawaan Laravel | 2 | smoke test framework |
| **Total** | **98 test / 294 assertion** | semua lulus ✅ |

---

## 11. Pembagian Tugas (Tim 2 Orang)

| Orang | Tugas | Output |
|---|---|---|
| **1 — Backend & AI** | 4 migration, 4 model, seeder, 25 endpoint, AI Microservice, 98 test, docs | repo backend + `ai-service/` |
| **2 — Frontend** | Layout Next.js, Beranda warga, Laporan Publik + modal filter, Tiket Saya, Form Buat Laporan (toggle Privat & Anonim), Detail + Stepper Timeline, Dashboard admin (Pie Chart + 4 metrik), Tabel Masuk, Tabel Riwayat, Modal Terima/Tolak/Tindak Lanjut | repo frontend |

Kontrak JSON untuk kedua sisi: `docs/README.md` §4.

---

## 12. Revisi dari SRS v1.0

| # | Aspek | SRS v1.0 | SRS v2.0 (ini) | Alasan |
|---|---|---|---|---|
| 1 | Jumlah kategori | 6 | **12** | Label model AI = 12 kategori dengan nama spesifik. 6 kategori awal (Isu Sosial, Edukasi) tidak cocok dengan output model → 8 dari 12 output akan ditolak |
| 2 | Model AI | 1 model (kategori) | **2 model** (kategori + urgensi) | Kebutuhan deteksi tingkat importance laporan |
| 3 | Konsep prioritas | tidak ada | **Field `priority` + `ai_priority`** | Konsep baru, di luar SRS v1 |
| 4 | Endpoint `/api/reports/public` | ada | tetap, + filter `priority` | konsistensi filter |
| 5 | Nominalisasi tabel log | `report_logs` | `reports_logs` | mengikuti class `ReportsLog` yang sudah ada |
| 6 | Threshold AI | 0.85 (fixed) | 0.85 **default**, hasil kalibrasi | angka 0.85 harus divalidasi, bukan diasumsikan |

---

## 13. Lampiran

| Dokumen | Isi |
|---|---|
| `docs/README.md` | Peta proyek, cara menjalankan, cheat sheet curl |
| `docs/01-arsitektur-dan-pola.md` | 8 pola Laravel yang dipakai |
| `docs/02-walkthrough-categories.md` | Bedah CategoriesController |
| `docs/03-walkthrough-auth.md` | Bedah AuthController + alur token |
| `docs/04-latihan-tahap-berikutnya.md` | Riwayat Tahap 1 (arsip) |
| `docs/05-modul-laporan.md` | Bedah Reports + Admin + Stats |
| `docs/06-ai-bert-dua-model.md` | Panduan AI BERT + kalibrasi threshold |
| `docs/07-microservice-ai.md` | Justifikasi arsitektur microservice |
| `ai-service/README.md` | Cara menjalankan service AI |
