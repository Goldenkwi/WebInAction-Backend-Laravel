# 06 — AI BERT: DUA MODEL INDOBERT P2 (Microservice)

Dokumen ini menjelaskan service AI: model apa yang dipakai, kenapa perlu
dilatih, bagaimana cara pakainya, dan bagaimana memvalidasi angkanya.

---

## 1. Ringkasan Model

| | Model KATEGORI | Model PRIORITAS |
|---|---|---|
| Base | `indobenchmark/indobert-base-p2` | sama |
| Arsitektur | `BertForSequenceClassification` | sama |
| Vocab | 50.000 WordPiece | 50.000 WordPiece |
| Hidden / Layer / Head | 768 / 12 / 12 | 768 / 12 / 12 |
| Jumlah label | **12** | **4** |
| Label | Infrastruktur, Kebersihan, Keamanan, Kesehatan, Transportasi, Pendidikan, IT & Jaringan, Keuangan, Pelayanan Publik, Lingkungan, Sosial, Lain-lain | Darurat, Tinggi, Sedang, Rendah |
| Ukuran `model.safetensors` | 498 MB | 498 MB |
| `max_length` saat inferensi | **256** token | **128** token |

> **Kenapa 256 dan 128?** Diambil dari statistik panjang teks dataset:
> kategori median 264 karakter (p90 624), prioritas median 102 (max 162).
> Dengan konvensi ~4 karakter per token, 256 token sudah menutup >95% data.

### ⚠️ PENTING: `model_max_length` = sentinel

`tokenizer_config.json` hasil training memuat:

```json
"model_max_length": 1000000000000000019884624838656
```

Itu **nilai sentinel** (artinya "tidak di-set"). Kalau tidak kita timpa,
PyTorch akan mencoba alokasi array sepanjang itu dan kehabisan memori.
Karena itu `truncation=True` + `max_length` **wajib** di-set eksplisit di
`app/inference.py`.

---

## 2. Kenapa IndoBERT P2 HARUS DILATIH?

Pertanyaan yang sering muncul: *"Bisa langsung dipakai tanpa training?"*

**Jawabannya: tidak bisa untuk output label.**

Dari metadata model di Hugging Face:

```json
"pipeline_tag": "feature-extraction",
"architectures": ["BertModel"]
```

IndoBERT P2 itu **masked language model** — dilatih dengan tugas MLM (masked
language modeling) + NSP di atas korpus Indo4B (23,43 GB teks). Yang dilatih
itu **bahasa**, bukan **tugas**.

| Yang bisa langsung dipakai | Yang tidak bisa |
|---|---|
| ✅ Menghasilkan **embedding** (vektor makna) teks Indonesia | ❌ Menghasilkan label `Infrastruktur` / `Keamanan` |
| ✅ Dipakai sebagai **feature extractor** | ❌ Punya confidence score klasifikasi |
| ✅ Bisa di-*fine-tune* | ❌ Jalan tanpa dilatih |

Jadi untuk menghasilkan "laporan ini kategori Infrastruktur dengan keyakinan
91%", **harus ada tahap fine-tuning** yang memasang *classification head*
dan Forever melatihnya.

Bukti pendukung: semua publikasi yang terindeks (emotion detection 84,43%,
sentiment, ABSA, NER, QA) **fine-tune** IndoBERT. Tidak ada satupun memakainya
as-is untuk klasifikasi.

### Yang TIDAK perlu dilatih ulang

Kalau lu mau ganti label set (mis. dari 12 ke 6 kategori), **modelnya tetap
bisa dipakai** — cukup training ulang *head*-nya (lebih cepat, ~1/5 waktu).
Tapi kalau ada kategori BARU yang tidak pernah dilihat model, ya harus
retrain.

---

## 3. Anatomi Data Latih (temuan penting)

Dataset di `ai-service/data/`:

| Dataset | Train | Test | Label | Seimbang |
|---|---|---|---|---|
| `dataset_tupoksi_v2` | 9.600 | 2.400 | 12 | 800 per label |
| `dataset_urgency_v2` | 4.800 | 1.600 | 4 | 1.200 per label |

### ✅ Tidak ada data leakage

```
tupoksi: 0/2400 baris test yang persis ada di train  (0.0%)
urgency: 0/1600 baris test yang persis ada di train  (0.0%)
```

Split-nya bersih → **angka accuracy yang lu dapet bisa dipertanggungjawabkan**
di proposal. Ini yang bikin hasil evaluasi(nnilai kredibel, bukan sekadar
angkamanis.

### ⚠️ 65% data kategori BUKAN laporan warga

```
train tupoksi : 9.600 baris →  6.242 (65.0%) berawalan "TUPOKSI:"
test  tupoksi : 2.400 baris →  1.526 (63.6%) berawalan "TUPOKSI:"
train urgency : 4.800 baris →      0 (0.0%) berawalan "TUPOKSI:"
```

Isi baris `TUPOKSI:` itu **deskripsi tugas pokok instansi**:

> *"TUPOKSI: Melaksanakan pengangkutan dan pengelolaan sampah domestik dan
> non-domestik; Memelihara kebersihan toi…"*

 bandingkan dengan yang urgency (100% keluhan organik & realistis):

> *"pecahnya pipa gas utama bau menyengat warga keracunan, akses jalan
> terputus total warga terisolasi butuh drop logistik udara"*

**Konsekuensi:** model **kategori** dilatih 2/3-nya untuk mengklasifikasi
*deskripsi instansi*, bukan *keluhan warga*. Model **urgensi** justru jauh
lebih baik fundamentalnya.

Ini bukan PROGRAM — ini **temuan**. Ada dua cara memakainya:

1. **Jujur & aman (rekomendasi):** andalkan model urgency lebih dari
   category. Pakai confidence category sebagai *saran*, bukan keputusan.
   Status tetap `pending` → Human-in-the-Loop jadi jaring pengaman.
2. **Tutup gap-nya:** tambah data keluhan warga realistis ke dataset
   kategori, lalu retrain head-nya (lihat `tools/train.py`).

Kalau lu memilih cara 1, tulis analisisnya di proposal — itu **nilai tambah**
yang men-satunyaROT: lu menunjukkan lu paham data lu, bukan cuma pakai.

---

## 4. Pemetaan Label → Database

Model mengembalikan label **berbentuk angka** (`label_id`), sementara
database pakai id sendiri. Jembatannya: kolom `categories.ai_label_id`.

```
categories.id = 1 (Infrastruktur)  <-> ai_label_id = 0
categories.id = 2 (Kebersihan)    <-> ai_label_id = 1
...
```

Pencocokan dilakukan di `AiBertService::resolveCategoryId()`:

```php
if (isset($category['label_id']) && is_numeric($category['label_id'])) {
    $match = Categories::where('ai_label_id', (int) $category['label_id'])->first();
    if ($match) {
        return $match->id;
    }
}
```

**Kenapa bukan cocokkan pakai nama string?** Karena namespec:
`"Lain-lain"` vs `"lainlain"`, `"IT & Jaringan"` vs `"IT_Jaringan"`.
Perbandingan string rapuh; angka tidak.

### Verifikasi kontrak

```bash
php artisan ai:check-labels
```

Command ini membaca `id2label` **asli dari model** (lewat `GET /health` AI
service), lalu mencocokkannya dengan database. Output:

```
  Label model   : 12 kategori
  Label database: 12 kategori
  Cocok         : 12
  Tidak cocok   : 0
  -> KONTRAK SESUAI, aman untuk demo.
```

Jalankan **sebelum demo**. Kalau ternyata tidak cocok, kategori bisa salah
diarahkan ke instansi yang keliru — dan itu baru ketahuan saat demo.

---

## 5. Kontrak API

### Request

```http
POST /analyze
Content-Type: application/json

{ "text": "lampu jalan mati dan kabel menjuntai di dekat sekolah" }
```

### Response

```json
{
  "category": { "label_id": 0, "label": "Infrastruktur", "confidence": 0.9312 },
  "urgency":  { "label_id": 2, "label": "Sedang",         "confidence": 0.8104 },
  "processing_ms": 74
}
```

Kalau salah satu model gagal: field-nya `null`, sisanya tetap jalan.

### Health

```http
GET /health

{
  "status": "ok",
  "labels": {
    "category": {"0": "Infrastruktur", "1": "Kebersihan", ...},
    "priority": {"0": "Darurat", "1": "Tinggi", "2": "Sedang", "3": "Rendah"}
  }
}
```

---

## 6. Menjalankan Service

```bash
cd ai-service

# 1. Virtual environment + dependency
python3 -m venv .venv
./.venv/bin/pip install --index-url https://download.pytorch.org/whl/cpu torch
./.venv/bin/pip install -r requirements.txt

# 2. Konfigurasi
cp .env.example .env

# 3. Jalankan
./.venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8100
```

Dokumentasi interaktif: <http://127.0.0.1:8100/docs>

> **Penting:** pakai index CPU untuk torch. Wheel default di Linux menarik
> CUDA toolkit (~3–5 GB) yang tidak diperlukan untuk inferensi CPU.

### Test cepat

```bash
curl -X POST http://127.0.0.1:8100/analyze \
  -H "Content-Type: application/json" \
  -d '{"text":"lampu jalan mati dan kabel menjuntai di dekat sekolah"}'
```

---

## 7. Kalibrasi Threshold (Bagian Paling Penting)

SRS menetapkan ambang confidence **0.85**. Angka itu **belum divalidasi**.

Model BERT yang dilatih dengan data kecil cenderung **overconfident**: bisa
memberi `confidence: 0.99` untuk tebakan yang **salah**. Kalau 0.85 dipakai
tanpa validasi, akibatnya: laporan salah-kategori **otomatis diteruskan ke
instansi yang salah** — dan badge hijau bikin admin complacency.

### Cara mendapatkan angka yang benar

```bash
cd ai-service
./.venv/bin/python tools/evaluate.py \
  --model models/category \
  --test data/tupoksi/dataset_tupoksi_v2_test.csv \
  --label-column kategori \
  --output reports/category_eval.md \
  --json-output reports/category_eval.json
```

#### Hasil Evaluasi Model KATEGORI (2.400 baris test set)

```
Accuracy            : 99.88%   (2397 / 2400 benar)
Macro-F1            : 99.87%
Latensi rata-rata   : 265.7 ms
Latensi median      : 221 ms
Latensi p95         : 481 ms
Throughput          : 3.8 prediksi/detik

ANALISIS KESENJANGAN DOMAIN
Teks 'TUPOKSI:' (deskripsi instansi) : 100.00%  (n=1526)
Keluhan organik warga                 :  99.66%  (n=874)
Selisih                              :   0.34 poin

KURVA THRESHOLD (auto-disposition)
 Threshold |  Coverage |   Auto |   Benar |   Akurasi
--------------------------------------------------------
      0.50 |    99.96% |   2399 |    2396 |    99.87%
      0.60 |    99.83% |   2396 |    2395 |    99.96%
      0.70 |    99.83% |   2396 |    2395 |    99.96%
      0.80 |    99.67% |   2392 |    2391 |    99.96%
      0.85 |    99.67% |   2392 |    2391 |    99.96%   <-- dipakai
      0.90 |    99.50% |   2388 |    2388 |   100.00%
      0.95 |    99.17% |   2380 |    2380 |   100.00%
```

**Confusion matrix** nyaris diagonal — hanya **3 kesalahan dari 2.400**:

| Label asli | Dipredict jadi |
|---|---|
| Infrastruktur | Transportasi (1) |
| Pelayanan Publik | IT & Jaringan (1) |
| Transportasi | Infrastruktur (1) |

#### Hasil Evaluasi Model PRIORITAS (1.600 baris test set)

```
Accuracy            : 98.31%   (1573 / 1600 benar)
Macro-F1            : 98.31%
Latensi rata-rata   : 144.0 ms
Latensi median      : 141 ms
Latensi p95         : 189 ms
Throughput          : 6.9 prediksi/detik

KURVA THRESHOLD
 Threshold |  Coverage |   Auto |   Benar |   Akurasi
--------------------------------------------------------
      0.50 |   100.00% |   1600 |    1573 |    98.31%
      0.60 |    99.62% |   1594 |    1570 |    98.49%
      0.70 |    99.44% |   1591 |    1568 |    98.55%
      0.80 |    97.12% |   1554 |    1541 |    99.16%
      0.85 |    96.81% |   1549 |    1538 |    99.29%   <-- dipakai
      0.95 |    95.62% |   1530 |    1522 |    99.48%
```

**Cara membaca kurva:**

- **Coverage** = berapa % laporan yang auto-dispose (kategorinya langsung
  terisi tanpa tunggu admin).
- **Akurasi** = dari laporan yang auto-dispose itu, berapa % yang benar.

Threshold yang bagus = **coverage tinggi** (banyak kerjaan berkurang) tapi
**akurasi tidak turun** di bawah akurasi keseluruhan.

#### Rekomendasi Threshold

| Model | Threshold | Coverage | Akurasi auto-dispose |
|---|---|---|---|
| Kategori | **0.85** | 99,67% | 99,96% |
| Prioritas | **0.85** | 96,81% | 99,29% |

```env
AI_BERT_CATEGORY_THRESHOLD=0.85
AI_BERT_PRIORITY_THRESHOLD=0.85
```

> **Temuan menarik:** threshold 0.85 yang ditetapkan SRS ternyata **tervalidasi**
> oleh kurva — di angka itu, akurasi auto-dispose justru **meningkat**
> (99,87% -> 99,96% untuk kategori) karena laporan dengan confidence rendah
> memang yang paling sering salah.

#### Latensi Gabungan vs NFR-01

```
Kategori   : mean 265.7 ms  |  p95 481 ms
Prioritas  : mean 144.0 ms  |  p95 189 ms
                   -----------------
Gabungan   : mean ~410 ms   |  p95 ~670 ms
Batas NFR-01:                 3.000 ms
```

Margin **4,5x** — jauh aman. IndoBERT base (124,5M parameter) di CPU sudah
cukup untuk produksi ringan.

#### ⚠️ CATATAN JUJUR: kenapa angkanya begitu tinggi?

Angka 99,88% itu **nyata**, tapi harus dibaca dengan hati-hati:

Test set berasal dari **distribusi yang sama** dengan data latih (keduanya
sintetis, dibangkitkan dari template yang sama). Model jadi sangat kuat di
data similar — bukan berarti equally kuat di **teks warga yang asli**.

Buktinya ada di live test: laporan warga sungguhan

> *"pecahnya pipa gas utama bau menyengat warga keracunan, akses jalan
> terputus total warga terisolasi butuh drop logistik udara"*

mendapat **confidence 0,677** untuk kategori — jauh di bawah confidence yang
model berikan pada teks sintetis. Artinya:

1. Di teks warga asli, model akan **lebih sering tidak yakin** -> lebih banyak
   laporan masuk antrean verifikasi manual. **Itu bagus** — Human-in-the-Loop
   bekerja seperti seharusnya.
2. Ambang 0,85 jadi **konservatif**, bukan agresif. Justru itu yang kita mau:
   auto-disposition hanya untuk kasus yang benar-benar jelas.
3. Untuk mengukur akurasi di domain nyata, butuh **~50-100 laporan warga
   asli** yang dilabeli manual. Ini PR lanjutan yang sangat bernilai untuk
   proposal (menunjukkan pemahaman akan batas model).

**Rumus kalimat untuk proposal:**

> *"Pada test set 2.400 baris, model klasifikasi kategori mencapai accuracy
> 99,88% (macro-F1 99,87%) dan model urgensi 98,31% (macro-F1 98,31%), dengan
> latensi inferensi gabung sekitar 410 ms — memenuhi NFR-01 (batas 3 detik)
> dengan margin 4,5 kali. Pada ambang confidence 0,85, sistem mendisposisi
> otomatis 99,67% laporan kategori dengan akurasi 99,96% pada subset
> auto-disposisi. Perlu dicatat bahwa test set merupakan distribusi sintetis
> yang sama dengan data latih; pada teks warga asli confidence model menurun
> signifikan, sehingga ambang 0,85 bersifat konservatif dan review manusia
> tetap berlaku."*

---

## 8. Review Kode: `app/inference.py`

```python
inputs = self.tokenizer(
    text,
    truncation=True,        # potong teks yang melebihi max_length
    max_length=self.max_length,
    padding=False,
    return_tensors="pt",
)
inputs = {key: value.to(self.device) for key, value in inputs.items()}

with torch.no_grad():       # tanpa gradient -> hemat memori & waktu
    logits = self.model(**inputs).logits

probabilities = torch.softmax(logits, dim=-1)[0]
confidence, index = torch.max(probabilities, dim=-1)
```

Tiga hal penting:

1. `truncation=True` + `max_length` → mencegah error memori (lihat §1).
2. `torch.no_grad()` → inference bukan training, gradient tidak perlu.
3. `softmax` → logits mentah diubah jadi probabilitas yang bisa dibandingkan
   antar label (dan inilah "confidence score"-nya).

---

## 9. Review Kode: `app/main.py` — Graceful Degradation

```python
try:
    classifiers["category"] = IndoBertClassifier(...)
except Exception as exc:
    logger.error("GAGAL memuat model kategori: %s", exc)
    if not settings.fail_soft:
        raise
```

Kalau model kategori gagal dimuat, service **tidak mati** — model prioritas
(yang berhasil) tetap melayani, dan field kategori bernilai `null`.

Di sisi Laravel (`AiBertService::callService()`), kegagalan pun tidak
melempar exception:

```php
} catch (Throwable $e) {
    Log::warning('AI Bert service tidak dapat dihubungi.', [...]);
    return null;
}
```

Hasil akhirnya: **form warga tetap berhasil**. Ini persis FR-AI-03 kondisi 3
+ NFR-05, dan hanya mungkin karena AI-nya dipisah jadi microservice.

---

## 10. Kalau Mau Retrain

```bash
# Di Google Colab (GPU gratis)
!pip install -q -r requirements.txt
!python tools/train.py \
    --model indobenchmark/indobert-base-p2 \
    --train data/tupoksi/dataset_tupoksi_v2_train.csv \
    --test  data/tupoksi/dataset_tupoksi_v2_test.csv \
    --label-column kategori \
    --output models/category \
    --epochs 3

# Lalu evaluasi + kalibrasi ulang threshold
!python tools/evaluate.py --model models/category --test data/tupoksi/dataset_tupoksi_v2_test.csv --label-column kategori
```

Untuk **menutup kesenjangan domain** (§3), tambah baris keluhan warga
realistis ke CSV latih dengan kolom yang sama (`teks`, `label`, `kategori`),
lalu latih ulang.

---

## 11. Checklist Sebelum Demo

- [ ] `php artisan ai:check-labels` → 12/12 & 4/4 cocok
- [ ] `GET /health` → dua model `loaded: true`
- [ ] `POST /analyze` → balasan punya `category` dan `urgency` terisi
- [ ] Threshold di `.env` **sudah di-set dari kurva kalibrasi**, bukan asal
- [ ] `php artisan test` → semua hijau
- [ ] `php artisan migrate:fresh --seed` → 12 kategori + 12 laporan demo
- [ ] Uji fallback: matikan service AI → kirim laporan → harus tetap 201
- [ ] Service AI jalan Again → kirim laporan → kategorinya terisi otomatis
- [ ] Dokumentasi Interaktif service hidup (<http://127.0.0.1:8100/docs>)
