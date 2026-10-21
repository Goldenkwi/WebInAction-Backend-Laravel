# 02 — WALKTHROUGH `CategoriesController`

File: `app/Http/Controllers/Api/CategoriesController.php`

Modul kategori sengaja dijadikan **contoh master data**: query sederhana,
sudah lengkap dengan validasi, otorisasi, dan manipulasi error. Dari sini
kamu bisa meniru pola yang sama untuk modul laporan.

---

## Kenapa Kategori Penting untuk Proyek Kita

Tabel `categories` bukan sekadar tabel biasa — dia adalah **daftar label**
yang dipelajari model AI BERT.

```
Warga kirim laporan
        │
        ├─ text: "lampu jalan mati, kabel menjuntai di dekat sekolah"
        │
        ▼
   AI BERT membaca teks
        │
        ├─ predicts : "Infrastruktur"  ← harus salah satu baris di tabel categories
        └─ confidence: 0.91
```

Karena itu:

- **Nama kategori harus unik** → tebakan AI tidak ambigu.
- **Kategori yang sudah dipakai laporan tidak boleh dihapus** → jangan sampai
  data historis jadi "yatim" (tanpa kategori).

---

## Anatominya

```php
class CategoriesController extends Controller
{
    public function index(Request $request)              // baris 79  : daftar
    public function show(Request $request, $category)   // baris 112 : detail
    public function store(StoreCategoryRequest $request) // baris 132: create
    public function update(UpdateCategoryRequest $request, $category) // 152
    public function destroy(Categories $category)        // baris 176: delete
}
```

Semua method mengembalikan `JsonResponse` (tipe hint). Ini bukan wajib, tapi
membantu IDE dan reader tahu bahwa return-nya pastilah JSON.

---

## METHOD 1 — `index()` : Daftar Kategori + Filter

```php
$categories = Categories::query()
    ->when(
        $request->filled('search'),
        fn ($query) => $query->search((string) $request->input('search'))
    )
    ->when(
        $request->boolean('with_counts'),
        fn ($query) => $query->withCount('reports')
    )
    ->orderBy('name')
    ->get();
```

### `->when($kondisi, $callback)`

Ini adalah **pola conditional query** paling umum di Laravel. Tanpa `when()`,
kode harus begini:

```php
// Versi-if (kurang enak)
if ($request->filled('search')) {
    $categories = $categories->search($request->input('search'));
}
if ($request->boolean('with_counts')) {
    $categories = $categories->withCount('reports');
}
```

Dengan `when()`, query tetap berupa **satu rantai fluent** yang mudah dibaca.

### `$request->filled('search')`

`filled()` = ada isinya **dan** tidak kosong. Jadi `?search=` (kosong) tidak
memicu filter. Bandingkan dengan:

| Method | `?search=` (kosong) | `?search=abc` |
| --- | --- | --- |
| `filled()` | false | true |
| `has()` | true | true |
| `boolean('with_counts')` | false | true (`"1"` → true) |

### `scopeSearch()` di model

Scope adalah method biasa dengan awalan `scope` yang bisa dipanggil langsung
di dalam query:

```php
// app/Models/Categories.php
public function scopeSearch(Builder $query, ?string $keyword): Builder
{
    if (blank($keyword)) {
        return $query;
    }

    return $query->where(function (Builder $query) use ($keyword) {
        $query->where('name', 'like', '%'.$keyword.'%')
            ->orWhere('icon', 'like', '%'.$keyword.'%');
    });
}
```

Dipanggil: `Categories::query()->search('infra')` → Laravel otomatis memetakan
ke `scopeSearch()`.

**Kenapa pakai `$query->where(function ($query) { ... })`?**

Kalau ditulis `->where('name','like',...)` lalu `->orWhere('icon','like',...)` tanpa
pembungkus, dan nanti digabung dengan filter lain, hasilnya jadi:

```sql
WHERE status = 'verified' AND name LIKE '%infra%' OR icon LIKE '%infra%'
```

Karena operator `AND` didahulukan `OR`, baris dengan `icon` cocok akan lolos
**walau** statusnya tidak cocok. Pembungkus `where(function(){...})` menghasilkan:

```sql
WHERE status = 'verified' AND (name LIKE '%infra%' OR icon LIKE '%infra%')
```

**Dan `use ($keyword)`** wajib ada agar variabel PHP bisa dibaca di dalam closure.

### Kenapa tidak pakai pagination di sini?

Kategori hanya ±6 baris dan frontend butuh daftar **penuh** untuk mengisi
dropdown "Pilih Kategori". Untuk daftar laporan (ribuan baris) nanti kita
pakai `->paginate(10)` + metadata pagination.

---

## METHOD 2 — `show()` : Detail + Route Model Binding

```php
public function show(Request $request, Categories $category): JsonResponse
{
    $category->loadCount('reports');

    return $this->sendResponse(
        (new CategoriesResource($category))->resolve($request),
        'Detail kategori berhasil dimuat.'
    );
}
```

### Route Model Binding

Tidak ada `Categories::find($request->id)` di sini. Laravel melihat:

- tipe-hint parameter = `Categories` (Model Eloquent)
- nama parameter = `$category`
- route-nya = `/categories/{category}`

…lalu otomatis mencari data dengan id dari URL. Kalau tidak ketemu → **404**
otomatis, lengkap dengan envelope (lihat `bootstrap/app.php`).

> **Aturan:** nama placeholder di URL **harus sama** dengan nama parameter
> di method. Kalau URL-nya `{id}` tapi parameter-nya `$category`, binding
> tidak jalan.

### `loadCount()` vs `withCount()`

- `withCount('reports')` → dipakai di **query** (sebelum `get()`), hasilnya
  langsung ikut ter-eager-load.
- `loadCount('reports')` → dipakai **setelah** modelnya ada (di sini), untuk
  memuat relasi tambahan pada satu model.

Hasilnya sama: atribut `reports_count` muncul di model.

---

## METHOD 3 — `store()` : Tambah Kategori

```php
public function store(StoreCategoryRequest $request): JsonResponse
{
    $category = Categories::create($request->validated());

    return $this->sendResponse(
        (new CategoriesResource($category))->resolve($request),
        'Kategori berhasil ditambahkan.',
        201
    );
}
```

### Kenapa `$request->validated()` dan bukan `$request->all()`?

`validated()` hanya mengembalikan field yang **ada di `rules()`** DAN lolos
validasi. Kalau pakai `$request->all()`, penyerang bisa mengirim:

```json
{ "name": "Transportasi", "id": 999, "created_at": "2020-01-01" }
```

…dan `id`/`created_at` ikut ter-set. `validated()` otomatis memfilter itu.

### Kenapa `201`?

`201 Created` = "resource baru berhasil dibuat". `200` berarti "ok, tapi
tidak ada yang baru dibuat". Membedakan keduanya penting supaya frontend
bisa menampilkan pesan yang tepat.

### Kenapa `authorize()` ada di Form Request padahal route sudah pakai `can:`?

Dua lapis proteksi (lihat `01-arsitektur-dan-pola.md`). Kalau suatu saat ada
developer yang lupa memasang middleware di route, Form Request tetap menahan.

---

## METHOD 4 — `update()` : Ubah Kategori (PATCH semantics)

```php
$category->update($request->validated());
```

Aturan `"sometimes"` di `UpdateCategoryRequest` membuat **field yang tidak
dikirim tidak berubah**:

```php
'icon' => ['sometimes', 'nullable', 'string', 'max:50'],
```

Kalau frontend hanya mengirim `name`, maka `icon` tetap yang lama.

### Aturan `unique` saat update

Ini jebakan klasik. Aturan ini:

```php
Rule::unique('categories', 'name')->ignore($this->route('category')?->id)
```

…wajib ada. Tanpa `ignore()`, kategori yang sedang diedit akan bentrok dengan
dirinya sendiri → user tidak bisa menyimpan tanpa mengubah nama.

`$this->route('category')` mengambil objek model yang sudah di-bind oleh
Route Model Binding, sehingga `->id` adalah id kategori yang sedang diedit.

### `->fresh()` pada response

```php
(new CategoriesResource($category->fresh()))->resolve($request)
```

`fresh()` memuat ulang data dari database. Gunanya: kalau ada trigger atau
event lain yang mengubah baris itu, response yang dikirim ke frontend tetap
menampilkan kondisi terbaru.

---

## METHOD 5 — `destroy()` : Hapus + Penjaga Integritas

```php
public function destroy(Categories $category): JsonResponse
{
    if ($category->reports()->exists()) {
        return $this->sendError(
            'Kategori masih digunakan oleh laporan sehingga tidak dapat dihapus. ...',
            409
        );
    }

    $category->delete();

    return $this->sendResponse(null, 'Kategori berhasil dihapus.');
}
```

### Kenapa tidak_AND biarkan database yang menolak?

Kalau foreign key-nya `restrict`, Laravel/DB akan melempar exception → user
menerima **500 Internal Server Error** tanpa penjelasan. Kalau `cascade`,
laporan historis ikut terhapus → **data loss**. Dua-duanya buruk.

Lebih baik ditolak dengan **409 Conflict** + pesan yang bisa dipahami user:
"Kategori masih dipakai, pindahkan laporan dulu."

### Kenapa `409`, bukan `400`?

| Kode | Arti |
| --- | --- |
| `400 Bad Request` | Permintaan-nya salah bentuk (mis. JSON rusak) |
| `409 Conflict` | Permintaan valid, tapi bentrok dengan state resource saat ini |

"Kategori masih dipakai" = bentuk request-nya benar, tapi tidak bisa dieksekusi
karena kondisi data. Itu definisi Conflict.

---

## RINGKASAN — POLA YANG BISA DITIRU

```
query → when() filter → get()
  ↓
(new CategoriesResource($model))->resolve($request)
  ↓
$this->sendResponse($data, ' pesan.', 200|201)
```

Dan untuk error bisnis:

```php
return $this->sendError('Penjelasan untuk user.', 409);
```

---

## LATIHAN (coba sendiri)

1. Tambahkan kolom `description` di tabel `categories` (migration baru) →
   tampilkan di `CategoriesResource`.
2. Tambahkan filter `?order_by=name|created_at` di `index()`.
3. Tambahkan endpoint `GET /api/categories/{category}/reports` yang
   menampilkan daftar laporan pada kategori tersebut (butuh `ReportsController`).
4. Ubah `destroy()` supaya kategori yang sudah dipakai **dinonaktifkan**
   (tambah kolom `is_active`) alih-alih dihapus.
