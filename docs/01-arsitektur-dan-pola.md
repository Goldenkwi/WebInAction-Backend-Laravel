# 01 — ARSITEKTUR & POLA LARAVEL YANG DIPAKAI DI PROYEK INI

Dokumen ini menjelaskan **mengapa** kode di proyek ini ditulis seperti itu.
Kalau kamu cuma hafal sintaks, kamu akan copy-paste. Kalau paham polanya,
kamu bisa bikin endpoint baru sendiri.

---

## POLA 1 — JSON Envelope (Base Controller)

**Masalah:** tanpa aturan baku, tiap endpoint mengembalikan bentuk JSON berbeda.
Frontend jadi berantakan: `json.data` untuk yang ini, `json.result` untuk itu.

**Solusi:** semua response dibungkus di `app/Http/Controllers/Controller.php`:

```php
protected function sendResponse(mixed $data = null, string $message = 'Berhasil.', int $code = 200, array $extra = [])
{
    return response()->json(array_merge([
        'success' => true,
        'message' => $message,
        'data'    => $data,
    ], $extra), $code);
}
```

**Pakai di controller:**

```php
return $this->sendResponse($data, 'Berhasil dimuat.');   // 200
return $this->sendResponse($data, 'Berhasil dibuat.', 201); // 201 Created
return $this->sendError('Tidak boleh dihapus.', 409);        // error
```

**Kapan pakai `$extra`?** Untuk metadata pagination:

```php
return $this->sendResponse($items, 'Daftar laporan.', 200, [
    'meta' => ['current_page' => 1, 'total' => 120],
]);
```

---

## POLA 2 — Form Request = Gerbang Validasi + Otorisasi

**File:** `app/Http/Requests/Api/`

Form Request adalah objek yang bekerja **sebelum** method controller dijalankan.
Laravel otomatis memanggilnya karena tipe-hint-nya di parameter controller:

```php
public function store(StoreCategoryRequest $request): JsonResponse
```

### Isi Form Request

```php
class StoreCategoryRequest extends ApiFormRequest
{
    // 1. Otorisasi: siapa yang boleh lewat?
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    // 2. Validasi: apa saja yang harus benar?
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:100', 'unique:categories,name'],
            'icon' => ['nullable', 'string', 'max:50'],
        ];
    }

    // 3. Pesan error ramah-user (opsional tapi berguna)
    public function messages(): array
    {
        return ['name.required' => 'Nama kategori wajib diisi.'];
    }
}
```

### Kenapa `ApiFormRequest` (base class) ada?

Karena bawaan Laravel untuk error validasi menghasilkan:

```json
{ "message": "...", "errors": { "email": ["..."] } }
```

…yang **tidak punya** `success` dan `data`. Override di `ApiFormRequest` memaksa
semua jalur error memakai envelope yang sama:

```php
protected function failedValidation(Validator $validator): void
{
    throw new HttpResponseException(response()->json([
        'success' => false,
        'message' => 'Data yang dikirim tidak valid. Periksa kembali isian Anda.',
        'data'    => null,
        'errors'  => $validator->errors()->toArray(),
    ], 422));
}
```

### Aturan validasi yang sering dipakai

| Aturan | Arti |
| --- | --- |
| `required` | Wajib diisi |
| `nullable` | Boleh kosong (dan tidak masuk ke `validated()` kalau kosong) |
| `sometimes` | Validasi HANYA kalau field-nya dikirim (dipakai untuk PATCH) |
| `string` / `integer` / `boolean` | Tipe data |
| `min:3` / `max:100` | Panjang minimum / maksimum |
| `email` | Format email |
| `unique:categories,name` | Unik di tabel/d kolom tsb |
| `confirmed` | Harus sama dengan field `xxx_confirmation` |
| `exists:users,id` | Nilainya harus ada di tabel tsb (FK) |

### Trik: normalisasi input sebelum divalidasi

```php
protected function prepareForValidation(): void
{
    $this->merge(['email' => Str::lower(trim($this->input('email'))]);
}
```

Dipakai di `RegisterRequest` & `LoginRequest` supaya email konsisten.

---

## POLA 3 — API Resource = shaping + penyensoran data

**File:** `app/Http/Resources/`

Tiga alasan memakai Resource daripada `return $model;`:

1. **Bentuk output terkontrol.** Field internal tidak ikut terkirim.
2. **Penyensoran data (NFR-02).** Tidak semua orang berhak lihat semua field.
3. **Konsistensi.** Semua endpoint yang sama selalu mengembalikan bentuk yang sama.

### Contoh penyensoran di `UserResource`

```php
$viewer  = $this->viewer ?? $request->user();
$isOwner = $viewer !== null && $viewer->id === $this->id;
$isAdmin = $viewer !== null && $viewer->isAdmin();

return [
    'email' => $this->when($isOwner || $isAdmin, $this->email),
];
```

`when($kondisi, $nilai)` → kalau kondisi `false`, key-nya **dihapus** dari JSON
(bukan diisi `null`). Kalau yang kamu mau `null`, tulis `->when($kondisi, $nilai, $fallback)`.

### Kenapa harus `->resolve($request)`?

Resource yang dikembalikan langsung dari controller akan dibungkus Laravel
menjadi `{ "data": { ... } }`. Itu bentrok dengan envelope kita (yang `data`-nya
sudah kita isi sendiri). Karena itu:

```php
CategoriesResource::collection($categories)->resolve($request)  // -> array
(new CategoriesResource($category))->resolve($request)         // -> array
```

### `whenCounted()`

```php
'reports_count' => $this->whenCounted('reports'),
```

Field ini **hanya muncul** kalau query-nya memakai `withCount('reports')`.
Kalau tidak, field-nya hilang (bukan `null`), jadi payload tetap ramping.

---

## POLA 4 — Gate = Capability-based authorization

**File:** `app/Providers/AppServiceProvider.php`

Gate memberi **nama** pada sebuah kemampuan, lalu route memanggilnya:

```php
// Provider
Gate::define('manage-categories', fn (User $user) => $user->isAdmin());

// Route
Route::post('/categories', [...])->middleware('can:manage-categories');
```

**Kenapa tidak cek manual di controller (`if (!$user->isAdmin()) ...`)?**

| Manual | Gate |
| --- | --- |
| Controller bisa "lupa" cek | Otomatis, ada di route |
| Logika tersebar di banyak file | Semua aturan di 1 tempat (mudah diaudit mentor) |
| Perlu cek "user ada atau tidak" | Otomatis 403 untuk tamu |

### Dua lapis proteksi

Route sudah pakai `can:manage-categories`, tapi `authorize()` di Form Request
juga dicek. Ini **belt & suspenders** — kalau suatu saat ada developer yang
lupa memasang middleware, Form Request tetap menahan.

---

## POLA 5 — Sanctum + token, bukan session

**Kenapa tidak session cookie?** Karena frontend Next.js dan backend Laravel
berada di domain/port berbeda (Next.js di 3000, Laravel di 8000).
Session cookie butuh konfigurasi CORS + `SANCTUM_STATEFUL_DOMAINS` yang
membingungkan. **Bearer token** jauh lebih sederhana dan cocok untuk REST API.

**Cara kerja token:**

```
Login  ->  server membuat token acak
       ->  token ASLI dikirim ke client
       ->  HASH token yang disimpan di tabel personal_access_tokens

Request berikutnya -> header "Authorization: Bearer <token>"
                    -> server lookup hash-nya -> dapat user
```

Persis seperti password: yang disimpan di database = **hash**, bukan nilai asli.
Makanya `plainTextToken` hanya bisa dilihat **sekali** (saat login).

---

## POLA 6 — Mass Assignment & Anti Privilege Escalation

**File:** `app/Models/User.php`

```php
#[Fillable(['name', 'email', 'password'])]   // 'role' SENGAJA TIDAK ADA
```

Kalau `role` ikut fillable, penyerang bisa kirim:

```json
{ "name": "x", "email": "y", "password": "z", "role": "verifier_admin" }
```

…langsung dapat hak admin. Karena itu role **tidak pernah** berasal dari client.
Server yang menentukan:

```php
public static function registerCitizen(string $name, string $email, string $password): static
{
    $user = new static;
    $user->name     = $name;
    $user->email    = $email;
    $user->password = $password;   // cast 'hashed' yang hash otomatis
    $user->role     = self::ROLE_CITIZEN;   // dikunci
    $user->save();
    return $user;
}
```

Ada test yang mengunci aturan ini: `test_client_cannot_register_as_admin`.

---

## POLA 7 — Audit Trail Append-Only (NFR-04)

**File:** `app/Models/ReportsLog.php`

```php
protected static function booted(): void
{
    static::updating(fn () => throw new LogicException('Log bersifat append-only.'));
    static::deleting(fn () => throw new LogicException('Log bersifat append-only.'));
}
```

Event `updating` & `deleting` di-attach di level model, jadi **semua** kode yang
mencoba update/hapus log akan gagal. Ini dijaga karena log adalah bukti
jejak digital: kalau bisa dihapus, riwayatnya bisa dimanipulasi.

---

## POLA BONUS — Rate Limiting (anti brute-force)

```php
// AppServiceProvider
RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)->by($request->ip().'|'.strtolower($request->input('email')));
});

// Route
Route::post('login', [...])->middleware('throttle:login');
```

Dibatasi per **(IP + email)**, supaya satu orang yang salah password tidak
mengunci seluruh kantor yang berada di IP sama. Percobaan ke-6 otomatis
menerima HTTP 429.

---

## RINGKASAN — Urutan Kerja Satu Request

```
Client (Next.js)
   │
   ▼
Middleware:  CORS → auth:sanctum (401) → can:manage-* (403) → throttle (429)
   │
   ▼
Form Request: authorize() (403) → rules() (422) → prepareForValidation()
   │
   ▼
Controller:  query Eloquent → ubah data → tentukan pesan
   │
   ▼
Resource:    pilih field → sensor data → bentuk array
   │
   ▼
Base Controller: bungkus jadi { success, message, data }
   │
   ▼
Client: json.success ? json.data : tampilkan json.message
```

---

## POLA 8 — Service Layer (Aturan Bisnis di Luar Controller)

**File:** `app/Services/AiBertService.php`, `app/Services/ReportService.php`

Controller idealnya **tidak** boleh berisi aturan bisnis. Aturan bisnis
dipindah ke service:

```php
// Controller - jadi ramping
public function store(StoreReportRequest $request): JsonResponse
{
    $report = $this->reportService->createFromCitizen(
        user: $request->user(),
        data: $request->safe()->except('image'),
        image: $request->file('image'),
    );

    return $this->sendResponse([...], $message, 201);
}
```

```php
// Service - semua aturan di sini
public function createFromCitizen(User $user, array $data, ?UploadedFile $image = null): Reports
{
    $imagePath = $image ? $this->storeImage($image, 'reports') : null;
    $text = trim($data['title'].' '.$data['description']);
    $analysis = $this->aiBert->analyze($text);
    // ... auto-disposition, DB::transaction, audit trail
}
```

**Kenapa pattern ini:**

| | Semua di Controller | Service Layer |
|---|---|---|
| Uji aturan bisnis | Harus lewat HTTP | Bisa langsung panggil method |
| Aturan bisa dilanggar | Mungkin, dari endpoint lain | Tidak, satu pintu |
| Controller | Makin lama makin gemuk | Tetap ramping |
| Fallback AI | Terlalu banyak `if` | Satu return `null` |

### Aturan penting: Service TIDAK BOLEH melempar exception untuk kegagalan "normal"

Kegagalan AI (timeout, service mati) itu **normal**, bukan bug. Jadi
`AiBertService` mengembalikan `null`, bukan melempar exception:

```php
} catch (Throwable $e) {
    Log::warning('AI Bert service tidak dapat dihubungi.', [...]);
    return null;   // <-- fallback, bukan crash
}
```

Kalau service melempar exception, form warga ikut mati — itu melanggar
FR-AI-03 kondisi 3 & NFR-05.

### Domain Exception untuk pelanggaran aturan bisnis

Kegagalan "normal" -> `return null`.
Pelanggaran aturan bisnis -> **exception domain** dengan status sendiri:

```php
// app/Exceptions/InvalidReportTransitionException.php
throw new InvalidReportTransitionException(sprintf(
    'Laporan dengan status "%s" tidak dapat diproses lagi.', $report->statusLabel()
));
```

```php
// bootstrap/app.php
$exceptions->render(function (InvalidReportTransitionException $e, Request $request) {
    if ($request->is('api/*')) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'data'    => null,
        ], $e->status());   // 409
    }
});
```

Ini memberi pesan yang **jelas** ke user ("laporan sudah ditindaklanjuti, tidak
bisa diubah lagi") alih-alih 500 Internal Server Error.

---

## RINGKASAN — Urutan Kerja Satu Request

```
Client (Next.js)
   |
   v
Middleware:  CORS -> auth:sanctum (401) -> can:manage-* (403) -> throttle (429)
   |
   v
Form Request: authorize() (403) -> rules() (422) -> prepareForValidation()
   |
   v
Controller:  panggil Service -> ambil hasil
   |
   v
Service:     aturan bisnis (BR-01, auto-disposition, audit trail)
   |            +-> AiBertService --HTTP--> AI Microservice (timeout 3 dtk)
   |                                                +-> null on failure (FR-AI-03)
   v
Resource:    pilih field -> sensor data -> bentuk array
   |
   v
Base Controller: bungkus jadi { success, message, data }
   |
   v
Client: json.success ? json.data : tampilkan json.message
```
