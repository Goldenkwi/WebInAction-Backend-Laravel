# 03 — WALKTHROUGH `AuthController` + Mekanisme Token

File: `app/Http/Controllers/Api/Auth/AuthController.php`
File pendukung: `app/Models/User.php`, `app/Http/Requests/Api/Auth/*`

---

## Konsep Besar: Kenapa Token, Bukan Session?

Frontend Next.js (port 3000) dan backend Laravel (port 8000) berada di origin
beda. Session cookie lintas origin butuh konfigurasi CORS +
`SANCTUM_STATEFUL_DOMAINS` yang merepotkan.

**Bearer token** jauh lebih sederhana:

```
Authorization: Bearer 5|kJd8sP0mXyZ...
```

Sisi server cukup mencocokkan token dengan tabel `personal_access_tokens`.
Sisi client cukup menyimpan string di `localStorage` (atau cookie httpOnly
kalau mau lebih aman) dan menempelkannya ke setiap request.

---

## Peta File yang Terlibat

```
app/Http/Controllers/Api/Auth/AuthController.php  ← endpoint
app/Http/Requests/Api/Auth/RegisterRequest.php   ← validasi registrasi
app/Http/Requests/Api/Auth/LoginRequest.php      ← validasi login
app/Http/Resources/UserResource.php               ← bentuk data user
app/Models/User.php                               ← role, hashing, createToken()
app/Providers/AppServiceProvider.php              ← RateLimiter 'login'
routes/api.php                                   → middleware auth:sanctum
```

---

## METHOD 1 — `register()` : Daftar Akun

```php
public function register(RegisterRequest $request): JsonResponse
{
    $user = User::registerCitizen(
        name: $request->validated('name'),
        email: $request->validated('email'),
        password: $request->validated('password'),
    );

    $token = $user->createToken('auth-token')->plainTextToken;

    return $this->sendResponse([
        'token'      => $token,
        'token_type' => 'Bearer',
        'user'       => (new UserResource($user))->forViewer($user)->resolve($request),
    ], 'Registrasi berhasil. Selamat datang!', 201);
}
```

### Bagian-bagian penting

**a) `$request->validated('name')`**

Bisa dipanggil dengan nama field untuk ambil satu nilai saja. Kalau tanpa
argumen, mengembalikan array berisi semua field yang lolos validasi.

**b) `User::registerCitizen()` — kenapa bukan `User::create([...])`?**

| | `User::create([...])` | `User::registerCitizen(...)` |
| --- | --- | --- |
| Bentuk | Mass-assignment | assignment per field |
| `role` | harus di `$fillable` (**berbahaya**) | dikunci `citizen` di dalam method |
| Risiko | client bisa kirim `role: admin` | mustahil |

Ingat aturan di `User`:

```php
#[Fillable(['name', 'email', 'password'])]   // 'role' TIDAK ada di sini
```

Kalau `role` ikut fillable, orang bisa POST `role: verifier_admin` dan langsung
menjadi admin. Test `test_client_cannot_register_as_admin` mengunci aturan ini.

**c) Password di-hash otomatis**

```php
protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
    ];
}
```

Artinya `$user->password = 'password123'` **tidak** menyimpan teks biasa, tapi
hash bcrypt. Kamu tidak perlu memanggil `Hash::make()` di controller.

> Bcrypt hanya memproses 72 byte pertama. Jadi `RegisterRequest` membatasi
> `max:72` — kalau tidak, password panjang akan dipotong diam-diam.

**d) `createToken()` vs `plainTextToken`**

```php
$user->createToken('auth-token')          // objek token
$user->createToken('auth-token')->plainTextToken  // string "5|kJd8..."
```

Yang disimpan di database adalah **hash** dari string itu (kolom `token`).
String aslinya (`plainTextToken`) hanya ada di memory dan dikirim ke client
**sekali saja**. Kalau token bocor, harus logout/revoke.

**e) Kenapa `->forViewer($user)`?**

`UserResource` menyembunyikan `email` dari user lain (NFR-02). Tapi saat
registrasi, `$request->user()` masih `null` (token baru dibuat, belum
ditempel di header). Padahal frontend **butuh** email user-nya sendiri.

`forViewer($user)` memberi tahu Resource: "untuk response ini, penampilnya
adalah user ini". Detail implementasinya ada di `UserResource`.

---

## METHOD 2 — `login()` : Login

```php
public function login(LoginRequest $request): JsonResponse
{
    $email    = $request->validated('email');
    $password = $request->validated('password');

    $user = User::where('email', $email)->first();

    if (! $user || ! Hash::check($password, $user->password)) {
        return $this->sendError('Email atau password salah.', 401);
    }

    $token = $user->createToken('auth-token')->plainTextToken;

    return $this->sendResponse([
        'token'      => $token,
        'token_type' => 'Bearer',
        'user'       => (new UserResource($user))->forViewer($user)->resolve($request),
    ], 'Login berhasil.');
}
```

### Dua hal yang sering salah di beginners

**1. Membandingkan password dengan `==`**

```php
// ❌ JANGAN
if ($user->password == $password) { ... }
```

`$user->password` adalah hash bcrypt. Yang benar:

```php
// ✅ BENAR
Hash::check($password, $user->password)
```

`Hash::check()` otomatis memakai algoritma yang sama dengan hash yang tersimpan
(artinya kalau nanti kamu ganti ke argon2, kodenya tetap jalan).

**2. Membocorkan email terdaftar (user enumeration)**

```php
// ❌ JANGAN
if (! $user) {
    return $this->sendError('Email ini belum terdaftar.', 401);   // bocor!
}
if (! Hash::check($password, $user->password)) {
    return $this->sendError('Password salah.', 401);              // bocor!
}

// ✅ BENAR - satu pesan untuk semua kasus
if (! $user || ! Hash::check($password, $user->password)) {
    return $this->sendError('Email atau password salah.', 401);
}
```

Dengan cara yang benar, penyerang **tidak bisa** membedakan "email tidak
terdaftar" vs "password salah" — dia hanya tahu "kombinasi itu salah".

### Rate limiting

Di `routes/api.php`:

```php
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
```

Aturannya di `AppServiceProvider`:

```php
RateLimiter::for('login', fn (Request $request) =>
    Limit::perMinute(5)->by($request->ip().'|'.strtolower($request->input('email')));
```

Maksimal 5 percobaan per menit untuk kombinasi (IP + email). Percobaan ke-6
menerima **429 Too Many Requests**.

> Dibatasi per (IP + email), bukan per IP saja — supaya satu orang yang salah
> password tidak mengunci seluruh kantor yang satu IP.

### Setiap device = token sendiri

Tiap kali login, token baru dibuat. Jadi:
- User login di HP → token A
- User login di laptop → token B
- Logout di HP → **hanya** token A yang dihapus, token B tetap hidup

---

## METHOD 3 — `logout()` : Hapus Token

```php
public function logout(Request $request): JsonResponse
{
    $request->user()->currentAccessToken()->delete();

    return $this->sendResponse(null, 'Logout berhasil. Sampai jumpa!');
}
```

`$request->user()` mengisi user dari token yang dikirim (middleware
`auth:sanctum`). `currentAccessToken()` mengembalikan objek token yang SEDANG
dipakai, jadi hanya token device ini yang dihapus.

**Logout semua device:**

```php
$request->user()->tokens()->delete();
```

**Test penting:** `test_logout_invalidates_token` membuktikan token yang sama
tidak bisa dipakai lagi setelah logout.

> ⚠️ Gotcha saat menulis test: user di-cache di memory selama satu test method.
> Panggil `$this->app['auth']->forgetGuards()` sebelum request kedua, kalau
> tidak test-nya akan "lolos" padahal tokennya sudah dihapus.

---

## METHOD 4 — `me()` : Cek Sesi

```php
public function me(Request $request): JsonResponse
{
    return $this->sendResponse(
        (new UserResource($request->user()))->resolve($request),
        'Profil berhasil dimuat.'
    );
}
```

Ini endpoint "restore session" di sisi Next.js: saat aplikasi dibuka, frontend
memanggil `/api/auth/me` dengan token yang disimpan:

- **200** → sesi masih valid, tampilkan dashboard sesuai `role`.
- **401** → token kadaluwarsa/bermasalah, arahkan ke halaman login.

---

## ALUR LENGKAP DI SISI NEXT.js

```ts
// 1. Login
const res  = await fetch(`${API}/auth/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email, password }),
})
const json = await res.json()

if (!json.success) {
  toast.error(json.message)          // "Email atau password salah."
  return
}

localStorage.setItem('token', json.data.token)
router.push(json.data.user.role === 'citizen' ? '/warga' : '/admin')
// ↑ FR-AUTH-02: arahkan otomatis sesuai role

// 2. Request berikutnya (setiap request yang butuh autentikasi)
const token = localStorage.getItem('token')
const res = await fetch(`${API}/reports/my`, {
  headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
})
if (res.status === 401) {
  localStorage.removeItem('token')
  router.push('/login')               // sesi habis
}

// 3. Logout
await fetch(`${API}/auth/logout`, {
  method: 'POST',
  headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
})
localStorage.removeItem('token')
```

---

## RINGKASAN — 5 Hal yang Harus Diingat

1. **Password** → `Hash::check()`, bukan `==`. Disimpan otomatis hash karena cast `hashed`.
2. **Role** → selalu dari server, tidak boleh dari input client.
3. **Pesan login gagal** → satu pesan generik untuk email salah & password salah.
4. **Token** → dikirim sebagai `Authorization: Bearer <token>`.
5. **Logout** → hapus baris token di database, bukan hanya di browser.

---

## LATIHAN (coba sendiri)

1. Tambahkan `POST /api/auth/refresh` — buat token baru tanpa login ulang.
2. Tambahkan "remember me": kalau request punya `remember=1`, jangan hapus token
   lama saat login baru.
3. Tambahkan `POST /api/auth/logout-all` — hapus semua token user.
4. Tulis test: warga yang belum login tidak bisa akses `/api/auth/me` (sudah ada),
   dan admin bisa hapus kategori (sudah ada).
