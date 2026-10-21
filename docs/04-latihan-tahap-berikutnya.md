> ✅ **ARSIP — SUDAH SELESAI (Tahap 2)**
>
> Modul laporan + admin + statistik + AI Microservice **sudah diimplementasikan**.
> Dokumen ini disimpan sebagai catatanTahapan 1 (saat modul laporan masih
> hanya kerangka).
>
> Untuk implementasi yang jadi, baca:
> - `docs/05-modul-laporan.md` — walkthrough Reports + Admin + Stats
> - `docs/06-ai-bert-dua-model.md` — panduan AI BERT + kalibrasi threshold
> - `docs/SRS.md` — spesifikasi final
>
> Di bawah ini berisi kerangka awal yang sudah digantikan.

---

# 04 — (ARSIP) KERANGKA MODUL LAPORAN + KONTRAK AI BERT

Dokumen ini adalah **kerangka kerja** untuk modul yang belum kamu kerjakan.
Nothing di sini sudah jadi — itu PR-mu. Tapi kerangka, kontrak JSON, dan
kode/contoh supaya kamu tidak mulai dari nol.

---

# BAGIAN A — MODUL LAPORAN (FR-REP, FR-AI, FR-ADM, FR-PUB)

## A.1 Urutan Prioritas (pilih mana dulu)

| Prioritas | Endpoint | Alasan |
| --- | --- | --- |
| 1 | `POST /api/reports` | Inti proyek: warga mengirim laporan + AI menganalisis |
| 2 | `GET /api/reports/{id}` | Detail + timeline (FR-PUB-02) |
| 3 | `GET /api/reports/my` | Citizen melihat laporannya |
| 4 | `PATCH /api/admin/reports/{id}/verify` | Inti alur Human-in-the-Loop |
| 5 | `PATCH /api/admin/reports/{id}/reject` | Alur penolakan |
| 6 | `POST /api/admin/reports/{id}/followup` | Tindak lanjut |
| 7 | `GET /api/reports/public` | Halaman laporan publik |
| 8 | `GET /api/admin/reports` | Tabel admin |
| 9 | `GET /api/stats/summary` | 4 kartu metrik dashboard |

## A.2 Yang SUDAH Disiapkan untukmu

- Tabel `reports` + `reports_logs` sudah dibuat (migration sudah sesuai SRS).
- Model `Reports` (konstanta status, relasi `user`/`category`/`logs`) sudah ada.
- Model `ReportsLog` sudah dilindungi **append-only** (NFR-04).
- Gate `manage-reports` sudah didaftarkan di `AppServiceProvider`.
- Config kontrak AI sudah ada di `config/services.php` (`services.ai_bert`).

Yang belum: controller, resource, request, service AI, route, test.

## A.3 Template Controller Laporan (pola yang sama seperti kategori)

```php
// app/Http/Controllers/Api/ReportsController.php
public function store(StoreReportRequest $request): JsonResponse
{
    // 1. Nomor tiket otomatis (FR-REP-03): LPR-YYYY-XXXX
    $ticketNumber = 'LPR-'.now()->format('Y').'-'.str_pad((string) (Reports::max('id') + 1), 4, '0', STR_PAD_LEFT);

    // 2. Ambil teks gabungan untuk AI (FR-AI-01)
    $text = $request->validated('title').' '.$request->validated('description');

    // 3. Tanya AI (pakai service, bukan Http:: langsung di controller)
    $ai = app(AiBertService::class)->predict($text);   // return array|null

    // 4. Simpan laporan
    $report = Reports::create([
        'ticket_number'   => $ticketNumber,
        'user_id'         => $request->user()->id,
        'category_id'     => $ai['category_id'] ?? null,   // FR-AI-03 kondisi 1
        'ai_pred_cat_id'  => $ai['category_id'] ?? null,   // FR-AI-02
        'ai_confidence'   => $ai['confidence'] ?? null,
        'report_type'     => $request->validated('report_type'),
        'title'           => $request->validated('title'),
        'description'     => $request->validated('description'),
        'incident_date'   => $request->validated('incident_date'),
        'location_text'   => $request->validated('location_text'),
        'is_anonymous'    => $request->boolean('is_anonymous'),
        'is_private'      => $request->boolean('is_private'),
        'status'          => Reports::STATUS_PENDING,     // selalu pending dulu
    ]);

    // 5. Tulis audit trail
    ReportsLog::create([
        'report_id'  => $report->id,
        'changed_by' => null,                            // null = oleh sistem AI
        'status'     => Reports::STATUS_PENDING,
        'note'       => $ai
            ? sprintf('Klasifikasi otomatis ke kategori %s (Confidence: %d%%)',
                $ai['category_name'], $ai['confidence'] * 100)
            : 'Laporan diterima. Modul AI sedang tidak tersedia, menunggu verifikasi manual.',
    ]);

    // 6. Kembalikan JSON sesuai contoh di SRS Bab 4
    return $this->sendResponse([
        'id'            => $report->id,
        'ticket_number' => $report->ticket_number,
        'title'         => $report->title,
        'report_type'   => $report->report_type,
        'status'        => $report->status,
        'is_anonymous'  => (bool) $report->is_anonymous,
        'is_private'    => (bool) $report->is_private,
        'ai_analysis'   => [
            'predicted_category_id'   => $report->ai_pred_cat_id,
            'predicted_category_name' => $ai['category_name'] ?? null,
            'confidence_score'        => $report->ai_confidence,
            'auto_disposed'           => (bool) ($report->ai_confidence >= config('services.ai_bert.confidence_threshold')),
        ],
        'created_at'    => $report->created_at?->toIso8601String(),
    ], 'Laporan berhasil dikirim dan telah dianalisis oleh sistem AI.', 201);
}
```

## A.4 Aturan bisnis yang WAJIB dijaga (dari SRS)

| Kode | Aturan | Where to enforce |
| --- | --- | --- |
| **BR-01** | `pending → verified → followed_up`; `pending → rejected`. Setelah `rejected`/`followed_up` = final | Service/Controller, cek status saat ini sebelum update |
| **BR-02** | `note` wajib untuk `verified`/`rejected`/`followed_up` | Form Request (`required_if`) |
| **BR-03** | Publik hanya tampilkan `is_private = false` **dan** status `verified`/`followed_up` | Query `reports/public` |
| **NFR-02** | Kalau `is_anonymous`, nama pelapor = `"Anonymous"` (kecuali admin) | `ReportsResource` |
| **NFR-03** | Bukti foto: `jpg,jpeg,png,webp`, maks 2 MB | `followup` request: `image` → `mimes:jpg,jpeg,png,webp|max:2048` |

### Contoh `scope` untuk aturan BR-01 & BR-03

```php
// app/Models/Reports.php
public function scopePublic($query): Builder
{
    return $query->where('is_private', false)
        ->whereIn('status', [self::STATUS_VERIFIED, self::STATUS_FOLLOWED_UP]);
}
```

## A.5 Penyensoran nama pelapor (NFR-02)

```php
// app/Http/Resources/ReportsResource.php
public function toArray(Request $request): array
{
    $viewer = $request->user();
    $isAdmin = $viewer?->isAdmin() ?? false;

    // Pelapor boleh lihat namanya sendiri; admin boleh lihat semuanya.
    $canSeeRealName = $isAdmin || ($viewer && $viewer->id === $this->user_id);

    return [
        'reporter_name' => $this->is_anonymous && ! $canSeeRealName
            ? 'Anonymous'
            : $this->user->name,
        // ...
    ];
}
```

## A.6 Timeline (FR-PUB-02)

`GET /api/reports/{id}` harus mengembalikan `timeline_logs` (lihat contoh JSON
di SRS Bab 4 Contoh 2):

```php
'timeline_logs' => ReportsLogResource::collection($report->logs)->resolve($request),
```

Dan di `ReportsLogResource`, `changed_by_name` fallback ke `"Sistem AI BERT"`
kalau `changed_by` null (log dari AI).

---

# BAGIAN B — KONTRAK AI BERT (FR-AI-01 s/d FR-AI-03)

## B.1 Jawaban: FastAPI lokal atau HuggingFace hosted?

> **Rekomendasi: pakai Python FastAPI lokal** yang membungkus model HuggingFace,
> **dengan** HuggingFace Inference API sebagai cadangan.

Alasannya (technis, bukan sekadar preferensi):

| Aspek | FastAPI lokal (rekomendasi) | HuggingFace Inference API |
| --- | --- | --- |
| **Risiko saat demo** | Nol — jalan offline | Tinggi: butuh internet + token + kuota free tier |
| **Confidence score** | Dikontrol penuh (softmax atas logit model) | Sering hanya label; skor perlu dibaca dari `raw`/metadata |
| **Multi-label** | Bebas: kamu yang tentukan threshold | Terbatas oleh model yang tersedia |
| **Latency** | ~50–200 ms setelah model termuat | ~300–1500 ms + koneksi |
| **Model** | `transformers` + `torch` (bisa fine-tune) | Model harus sudah ada di Hub |
| **Biaya** | Gratis (komputer sendiri) | Free tier terbatas, paid kalau skala naik |
| **Fallback (FR-AI-03)** | Tinggal `try/catch` | Butuh retry + deteksi rate limit |

**Catatan untuk demo:** kalau laptop demo tidak kuat menjalankan PyTorch, pakai
**colab** atau jalankan service AI di **server hosting** (mis. Railway / Render /
VPS ubuntu) — bukan HuggingFace hosted — supaya kontrak API-nya tetap sama.

## B.2 Kontrak API (baku — dipakai Laravel ⇄ Python)

### Request (Laravel → Python)

```http
POST /predict
Content-Type: application/json

{
  "text": "lampu jalan mati dan kabel menjuntai di dekat SDN 03",
  "categories": ["Infrastruktur", "Keamanan", "Isu Sosial", "Edukasi", "Kesehatan", "Lainnya"]
}
```

`categories` dikirim dari database supaya **daftar label AI dan daftar label
kategori di aplikasi selalu sama**. Kalau ada kategori baru, tidak perlu
retrain model.

### Response (Python → Laravel)

```http
HTTP/1.1 200 OK
Content-Type: application/json

{
  "category": "Infrastruktur",
  "category_id": 1,
  "confidence": 0.91,
  "processing_ms": 87
}
```

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `category` | string | Nama kategori hasil prediksi |
| `category_id` | int | Id di tabel `categories` (dikirim balik agar tak perlu lookup lagi) |
| `confidence` | float | 0.00 – 1.00 (softmax) |
| `processing_ms` | int | Untuk monitoring, opsional |

> Kalau Python tidak tahu `category_id`, cukup kirim `category` (nama) dan
> Laravel lookup sendiri. **Tapi kirim `category_id` lebih hemat 1 query.**

### Error dari sisi AI

```http
HTTP/1.1 500 Internal Server Error
{ "error": "model_not_loaded" }
```

Laravel harus tetap menyimpan laporan dengan status `pending` (FR-AI-03
kondisi 3 / NFR-05). **Jangan sampai form warga gagal.**

## B.3 Implementasi Sisi Python (sketsa)

```python
# ai_service/main.py
from fastapi import FastAPI
from transformers import pipeline

app = FastAPI()

# Model dimuat SATU KALI saat service start (bukan per request).
classifier = pipeline(
    "text-classification",
    model="your-namespace/bert-indo-complaint",   # model hasil fine-tuning
    top_k=None,                                    # butuh skor semua label
)

@app.post("/predict")
def predict(payload: dict):
    text = payload["text"]
    labels = payload.get("categories", [])

    raw = classifier(text[:512])          # BERT maksimal 512 token
    best = max(raw, key=lambda r: r["score"])

    category = best["label"]
    return {
        "category": category,
        "category_id": labels.index(category) + 1 if category in labels else None,
        "confidence": round(best["score"], 4),
    }
```

Menjalankan:

```bash
pip install fastapi uvicorn transformers torch
uvicorn ai_service.main:app --host 127.0.0.1 --port 8000
```

## B.4 Implementasi Sisi Laravel (sketsa — PR-mu)

Buat `app/Services/AiBertService.php`:

```php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiBertService
{
    /**
     * @return array{category_id: ?int, category_name: ?string, confidence: ?float}|null
     */
    public function predict(string $text, array $categories): ?array
    {
        $url      = config('services.ai_bert.url');
        $timeout  = config('services.ai_bert.timeout');   // NFR-01: 3 detik

        try {
            $response = Http::timeout($timeout)->post($url, [
                'text'       => $text,
                'categories' => $categories,
            ]);

            if (! $response->successful()) {
                Log::warning('AI BERT service gagal', ['status' => $response->status()]);
                return null;
            }

            return $response->json();
        } catch (Throwable $e) {
            // Timeout / service down -> fallback (FR-AI-03 kondisi 3)
            Log::warning('AI BERT service tidak tersedia: '.$e->getMessage());
            return null;
        }
    }
}
```

### Where to call it

Jangan memanggil HTTP di dalam controller langsung — taruh di **Service** supaya:

- mudah di-**mock** saat testing (`$this->mock(AiBertService::class)`),
- mudah di-ganti driver (local ⇄ HuggingFace) tanpa mengubah controller,
- satu tempat untuk timeout & retry.

### Auto-disposition (FR-AI-03)

```php
$threshold = config('services.ai_bert.confidence_threshold');   // 0.85

if ($ai && $ai['confidence'] >= $threshold) {
    // Kondisi 1: AI yakin -> isi category_id dengan hasil tebakan AI.
    // Status tetap 'pending' (opsional auto-'verified' per SRS).
    $report->category_id = $ai['category_id'];
} else {
    // Kondisi 2: AI tidak yakin -> masuk antrean verifikasi manual.
    $report->category_id = null;
}
```

> **Rekomendasi untuk demo:** tetap biarkan status `pending` walau confidence
> tinggi, tapi tampilkan badge hijau **"Rekomendasi AI Sangat Yakin"** di
> halaman admin. Alasannya: alur tombol **Verifikasi → Tindak Lanjut** tetap
> terlihat jelas saat demo, dan Human-in-the-Loop-nya utuh (cocok dengan
> prinsip proyek ini).

## B.5 Mock AI untuk development frontend

Supaya frontend-mu tidak nunggu service Python, buat **fake**:

```php
// routes/api.php (temporary, HAPUS sebelum demo)
Route::post('/mock/ai', fn () => response()->json([
    'category' => 'Infrastruktur', 'category_id' => 1, 'confidence' => 0.91,
]));
```

Atau ubah `services.ai_bert.url` ke mock server (mis. `https://mocki.io/...`).

## B.6 Parameter `.env` (sudah disiapkan di `config/services.php`)

```env
AI_BERT_DRIVER=local
AI_BERT_URL=http://127.0.0.1:8000/predict
AI_BERT_TIMEOUT=3
AI_BERT_CONFIDENCE_THRESHOLD=0.85

HUGGINGFACE_TOKEN=
HUGGINGFACE_MODEL=indonesia/bert-base-multilingual-cased
HUGGINGFACE_API_URL=https://api-inference.huggingface.co/models
```

---

# BAGIAN C — CHECKLIST SEBELUM DEMO

- [ ] `php artisan migrate:fresh --seed` jalan tanpa error
- [ ] `php artisan test` → semua hijau
- [ ] 3 akun dummy bisa login
- [ ] Warga bisa kirim laporan → dapat nomor tiket `LPR-YYYY-XXXX`
- [ ] AI service aktif → `ai_confidence` terisi
- [ ] AI service DIMATIKAN → laporan tetap tersimpan, status `pending` (FR-AI-03)
- [ ] Admin bisa verifikasi → memo wajib (BR-02)
- [ ] Admin bisa tolak → alasan wajib
- [ ] Admin instansi bisa tindak lanjut → status `followed_up` + feedback
- [ ] Laporan privat tidak muncul di `/api/reports/public` (BR-03)
- [ ] Nama pelapor anonim tampil `"Anonymous"` di halaman publik (NFR-02)
- [ ] Upload bukti ditolak kalau > 2 MB atau bukan jpg/png/webp (NFR-03)
- [ ] Timeline di detail laporan menampilkan 3 tahap (FR-PUB-02)
- [ ] Dashboard menampilkan 4 metrik (FR-STAT-01)

---

# BAGIAN D — IDE IDE PENGEMBANGAN (bonus, kalau masih ada waktu)

1. **Klasifikasi spam** (FR-AI-04): sebelum klasifikasi, cek apakah teks
   mengandung kata kasar/iklan → kalau iya, tandai `needs_review`.
2. **Auto-draft balasan admin**: pakai tebakan AI untuk membuat draf memo.
3. **Grafik tren laporan per kategori** per bulan (chart di dashboard).
4. **Export CSV** laporan untuk admin.
5. **Webhook / email notifikasi** ke admin saat ada laporan `pending` baru.
