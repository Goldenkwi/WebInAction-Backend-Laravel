# 05 — WALKTHROUGH MODUL LAPORAN (Reports + Admin + Stats)

Dokumen ini bedah modul yang paling "berat" logikanya: siklus hidup satu
laporan dari warga sampai selesai ditangani.

---

## Peta Modul

```
Warga kirim laporan
        │
        ▼
ReportsController@store ──► ReportService::createFromCitizen()
        │                          │
        │                          ├──► AiBertService::analyze()   (HTTP ke AI Microservice)
        │                          ├──► Reports::forceCreate()     (status = pending)
        │                          └──► ReportsLog::create()       (audit trail baris 1)
        ▼
    Admin Verifikator
        │  PATCH /api/admin/reports/{id}/verify
        ▼
ReportService::verify() ──► status = verified + log baris 2 (Memo Penugasan)
        │
        ▼
    Admin Instansi
        │  POST /api/admin/reports/{id}/followup
        ▼
ReportService::followUp() ──► status = followed_up + log baris 3 (Feedback + foto bukti)
```

---

## 1. Kenapa ada `ReportService`?

Semua aturan bisnisBERADA di service, bukan di controller:

| Aturan | Ditempelkan di |
|---|---|
| Nomor tiket otomatis | `ReportService::generateTicketNumber()` |
| Auto-disposition kategori (FR-AI-03) | `ReportService::createFromCitizen()` |
| Transisi status (BR-01) | `ReportService::assertTransition()` |
| Audit trail (NFR-04) | `ReportService::writeLog()` |
| Penyimpanan foto | `ReportService::storeImage()` |

**Controller cuma 3 baris untuk create:**

```php
$report = $this->reportService->createFromCitizen(
    user: $request->user(),
    data: $request->safe()->except('image'),
    image: $request->file('image'),
);
```

Konsekuensinya: aturan bisnis bisa diuji **tanpa lewat HTTP**, dan tidak
bisa dilanggar dari endpoint lain.

---

## 2. `ReportService::createFromCitizen()` — 6 langkah

### Langkah 1: Simpan foto DULU

```php
$imagePath = $image ? $this->storeImage($image, 'reports') : null;
```

Kenapa sebelum database? Kalau upload-nya gagal (disk penuh, format salah),
kita tidak mau ada laporan yang setengah jadi. Kebalikannya: kalau upload
berhasil tapi insert DB gagal, ada file yatim di storage — itu jauh lebih
ringan masalahnya (file sampah, bukan data hilang).

### Langkah 2: Gabungkan teks untuk AI (FR-AI-01)

```php
$text = trim($data['title'].' '.$data['description']);
```

SRS: "Laravel menggabungkan title dan description, lalu mengirimkannya ke
Endpoint AI BERT". Judul sering sudah Summarize masalahnya, deskripsi
memberi konteks. Dua-duanya valuable.

### Langkah 3: Tanya AI

```php
$analysis = $this->aiBert->analyze($text);
```

Kalau AI mati → `$analysis = null`. **Tidak ada exception.** Lanjut ke
langkah berikutnya dengan data kosong.

### Langkah 4: Auto-disposition (FR-AI-03)

```php
$threshold = (float) config('services.ai_bert.category_confidence_threshold', 0.85);
$autoFill  = $analysis
    && $analysis['category_id'] !== null
    && $analysis['category_confidence'] !== null
    && $analysis['category_confidence'] >= $threshold;
```

Kalau AI yakin → `category_id` langsung diisi tebakannya. Kalau tidak →
`category_id` = null (menunggu admin).

> **Kenapa status tetap `pending` walau confidence tinggi?**
> Karena Human-in-the-Loop di SRS harus tetap utuh. Tombol Verifikasi tetap
> punya arti, dan admin tetap reviewing. Lihat `docs/06` untuk alasannya.

### Langkah 5 & 6: Simpan + catat audit trail

```php
$report = DB::transaction(function () use (...) {
    $report = Reports::create([... 'status' => Reports::STATUS_PENDING]);

    $this->writeLog($report, Reports::STATUS_PENDING, $this->buildInitialNote($analysis, $autoFill), null);

    return $report;
});
```

`DB::transaction()` memastikan laporan DAN log-nya konsisten — tidak mungkin
ada laporan tanpa baris log (atau sebaliknya).

---

## 3. BR-01 — Penjaga Transisi Status

```php
protected function assertTransition(Reports $report, array $allowedFrom): void
{
    if (in_array($report->status, $allowedFrom, true)) {
        return;
    }

    throw new InvalidReportTransitionException(sprintf(
        'Laporan dengan status "%s" tidak dapat diproses lagi. '
        .'Tindakan ini hanya bisa dilakukan pada laporan berstatus: %s.',
        $report->statusLabel(),
        implode(' / ', $allowedFrom)
    ));
}
```

Dipakai di 3 tempat:

| Aksi | `allowedFrom` |
|---|---|
| `verify()` | `[pending]` |
| `reject()` | `[pending]` |
| `followUp()` | `[verified]` |

Exception-nya dirender jadi **409 Conflict** di `bootstrap/app.php`:

```php
$exceptions->render(function (InvalidReportTransitionException $e, Request $request) {
    if ($request->is('api/*')) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => null,
        ], $e->status());
    }
});
```

**Kenapa 409 dan bukan 400?** Request-nya bentuknya benar, tapi tidak bisa
dieksekusi karena state resource sekarang. Itu definisi Conflict.

---

## 4. BR-02 — Note Wajib (di level Form Request, bukan manual)

```php
// VerifyReportRequest
'note' => ['required', 'string', 'min:10', 'max:2000'],
```

Kalau note kosong → **422** dan status **tidak berubah**. Bukan "gagal
dengan pesan error" —)[[tidak ada yang berubah sama sekali.

 Kenapa di Form Request dan bukan `if (empty($note)) { ... }` di controller?

| Manual di controller | Form Request |
|---|---|
| Nilainya mudah "lupa" checked di satu endpoint | Dijamin di semua endpoint yang pakai request itu |
| Validasi & bisnis tercampur | Terpisah jelas |
| Pesan error tidak konsisten | Otomatis mengikuti format envelope |

---

## 5. NFR-02 — Penyensoran Nama Pelapor

Di `ReportsResource`:

```php
$canSeeName = $isOwner || $isAdmin;

'reporter_name' => $this->is_anonymous && ! $canSeeName
    ? 'Anonymous'
    : $this->user?->name,
```

Tiga skenario:

| Yang melihat | Laporan anonim → tampil |
|---|---|
| Orang lain (warga/admin lain) | `"Anonymous"` |
| Pelapor sendiri | Nama aslinya |
| Admin | Nama aslinya |

Alasan pelapor boleh lihat namanya sendiri: supaya dia tahu laporannya
terhubung ke akunnya, dan supaya tidak bingung saat sistematis lewat.

---

## 6. BR-03 — Scope Laporan Publik

```php
public function scopePublic(Builder $query): Builder
{
    return $query->where('is_private', false)
        ->whereIn('status', [self::STATUS_VERIFIED, self::STATUS_FOLLOWED_UP]);
}
```

Dua syarat: **tidak privat** DAN **sudah diverifikasi/ditindaklanjuti**.

Laporan `pending` (masih antrean) dan `rejected` (tidak valid) tidak
dipajang. Ini mencegah etalase publik dipenuhi spam dan hoaks.

---

## 7. FR-ADM-03 — Rekam Koreksi Admin

Tiga kolom terpisah menyimpan "apa yang ditebak AI" vs "apa yang diputuskan
manusia":

| Kolom | Ditulis oleh | Bisa diubah admin? |
|---|---|---|
| `ai_pred_cat_id` | AI | ❌ tidak |
| `category_id` | Admin (defaultnya = AI) | ✅ ya |
| `ai_confidence` | AI | ❌ tidak |

```php
public function isAiCorrected(): bool
{
    return $this->ai_pred_cat_id !== null
        && $this->category_id !== null
        && $this->ai_pred_cat_id !== $this->category_id;
}
```

Kalau `isAiCorrected()` = true, berarti **admin tidak setuju dengan AI**.
Data itulah yang dipakai untuk mengevaluasi akurasi model —-value paling
berharga di seluruh sistem.

> Ingat: `ai_pred_cat_id` TIDAK PERNAH diubah admin. Kalau diubah, data
> evaluasinya jadi bohong.

---

## 8. Prioritas — 3 kolom, 2 sumber

```php
'ai_priority'             => 'tinggi',   // output mentah model (tidak diubah)
'ai_priority_confidence'  => 0.83,       // keyakinan model
'priority'                => 'tinggi',   // prioritas EFEKTIF (bisa dikoreksi admin)
```

Tiga kolom karena ada dua sumber informasi (AI & admin) dan kita tidak mau
kehilangan jejaknya — sama seperti kategori.

Kalau AI mati → ketiganya `NULL` (tidak diketahui), **bukan** default palsu
seperti "sedang".

---

## 9. Endpoint Admin

| Endpoint | Input | Output |
|---|---|---|
| `GET /api/admin/reports` | filter: `status`, `category_id`, `priority`, `search`, `start_date`, `end_date`, `per_page` | daftar + `meta` pagination |
| `PATCH /api/admin/reports/{id}/verify` | `category_id`, `note` | status → `verified` |
| `PATCH /api/admin/reports/{id}/reject` | `note` | status → `rejected` |
| `POST /api/admin/reports/{id}/followup` | `note`, `proof_img` (file) | status → `followed_up` |

Sorting di tabel admin: **prioritas tertinggi dulu**, baru yang terbaru:

```php
->orderByRaw(
    "CASE priority WHEN 'darurat' THEN 4 WHEN 'tinggi' THEN 3 "
    ."WHEN 'sedang' THEN 2 WHEN 'rendah' THEN 1 ELSE 0 END DESC"
)
->latest()
```

---

## 10. Endpoint Statistik (FR-STAT-01)

`GET /api/stats/summary` mengembalikan:

```json
{
  "total_reports": 13,
  "pending_reports": 3,
  "completed_reports": 4,
  "average_resolution_days": 1.5,
  "by_status": [ { "status": "pending", "label": "Menunggu", "count": 3 } ],
  "by_category": [ { "category_id": 1, "name": "Infrastruktur", "count": 5 } ],
  "by_priority": [ { "priority": "darurat", "label": "Darurat", "count": 1 } ]
}
```

`by_status`, `by_category`, `by_priority` dipakai untuk **Pie Chart** di
dashboard admin.

### Cakupan statistik per instansi (agency_admin)

`GET /api/stats/summary` boleh dipanggil semua user login, tetapi cakupan
angkanya mengikuti role:

| User | Cakupan angka |
|---|---|
| `verifier_admin` | seluruh sistem |
| `citizen` | seluruh sistem (hanya tampilan publik) |
| `agency_admin` | hanya `reports.assigned_agency_id = users.agency_id` |
| `agency_admin` tanpa `agency_id` | nol (tidak fallback ke seluruh sistem) |

Filter ini berlaku untuk **semua** angka, bukan hanya `total_reports`:

- `total_reports`, `pending_reports`, `verified_reports`,
  `completed_reports`, `rejected_reports`,
- `average_resolution_days` (query log juga di-join ke `reports` lalu difilter),
- `by_status`, `by_category`, `by_priority`.

Implementasinya di `StatsController`:

```php
$agencyId = $this->agencyScopeId($request);   // null untuk verifier
$reports  = $this->reportsQuery($agencyId);   // where assigned_agency_id
```

Jadi dashboard DPUPR tidak akan menampilkan angka laporan Dishub, walau
keduanya memakai endpoint yang sama.

### Cara hitung rata-rata waktu penyelesaian

```php
->where('reports_logs.status', Reports::STATUS_FOLLOWED_UP)
->join('reports', 'reports.id', '=', 'reports_logs.report_id')
```

Waktu penyelesaian = `reports.created_at` → `reports_logs.created_at` (baris
log `followed_up`).

**Kenapa dihitung di PHP, bukan SQL?** Karena fungsi tanggal berbeda antar
database: `julianday()` cuma ada di SQLite, `DATEDIFF()` cuma di MySQL.
Hitung di PHP → kodenya sama persis di mana pun.

---

## 11. Format Tanggal Indonesia

Di `ReportsLogResource`:

```php
'created_at_label' => $this->created_at?->locale('id')->translatedFormat('d M Y, H:i'),
```

Contoh output: `19 Okt 2026, 16:30` — cocok dengan contoh JSON di SRS Bab 4.

`created_at` (ISO 8601) tetap dikirim juga, jadi frontend bebas format ulang
kalau perlu.

---

## 12. Kenapa `forceCreate()` di Seeder?

```php
$report = Reports::forceCreate([... 'created_at' => $createdAt]);
```

Kolom `created_at` **tidak** ada di `$fillable` (timestamp bukan input user).
Akibatnya `Reports::create(['created_at' => ...])` akan **mengabaikan**
timestamp itu, dan semua laporan demo jadi ber tanggal "sekarang".

`forceCreate()` memakai `forceFill()` yang melewati proteksi mass-assignment
— cocok untuk seeder yang memang perlu mengatur timestamp historis.

---

## RINGKASAN — 10 aturan bisnis & di mana dijaga

| Kode | Aturan | Dijaga di | Diberi test |
|---|---|---|---|
| FR-AI-01 | Gabung title + description | `ReportService` | ✅ |
| FR-AI-02 | Simpan prediksi AI | `reports.ai_*` | ✅ |
| FR-AI-03 | Auto-disposition / fallback | `ReportService` | ✅ |
| FR-ADM-01 | Verifikasi + memo | `Admin\ReportController` | ✅ |
| FR-ADM-02 | Tolak + alasan | `Admin\ReportController` | ✅ |
| FR-ADM-03 | Rekam koreksi AI | `Reports::isAiCorrected()` | ✅ |
| FR-ADM-04 | Tindak lanjut + bukti | `ReportService::followUp()` | ✅ |
| FR-PUB-02 | Timeline | `ReportDetailResource` | ✅ |
| BR-01 | Transisi satu arah | `ReportService::assertTransition()` | ✅ |
| BR-02 | Note wajib | Form Request | ✅ |
| BR-03 | Visibilitas publik | `Reports::scopePublic()` | ✅ |
| NFR-01 | Timeout 3 detik | `AiBertService` | ✅ |
| NFR-02 | Anonimitas | `ReportsResource` | ✅ |
| NFR-03 | Batas file 2 MB | Form Request | ✅ |
