<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * ============================================================================
 * AUTH CONTROLLER  (FR-AUTH-01 & FR-AUTH-02)
 * ============================================================================
 * Endpoint yang ditangani:
 *   POST /api/auth/register -> daftar akun warga          (Publik)
 *   POST /api/auth/login    -> login, mengembalikan token     (Publik)
 *   POST /api/auth/logout   -> hapus token sesi ini       (Semua yang login)
 *   GET  /api/auth/me       -> profil user yang login      (Semua yang login)
 *
 * ---------------------------------------------------------------------------
 * KONSEP 1: Mekanisme autentikasi (Laravel Sanctum - Personal Access Token)
 * ---------------------------------------------------------------------------
 * Alur dariquierda ke kanan:
 *
 *   1. POST /api/auth/login
 *      Client kirim  : { email, password }
 *      Server        : cek password dengan Hash::check() -> jika benar,
 *                      buat token acak, simpan hash-nya di tabel
 *                      personal_access_tokens, kembalikan token ASLI ke client.
 *      Client        : simpan token (localStorage / cookie httpOnly)
 *
 *   2. Request berikutnya  ->  header "Authorization: Bearer <token>"
 *      Middleware auth:sanctum -> cari token, ambil user-nya, taruh di $request->user()
 *
 *   3. POST /api/auth/logout -> hapus baris token dari database.
 *      Karena token dihapus dari DB, token tersebut langsung tidak berlaku
 *      (token seperti "kartu akses", bukan seperti session yang harus di-expire).
 *
 * CATATAN KEAMANAN: token asli (plainTextToken) hanya ditampilkan SEKALI,
 * di response login. Yang disimpan di database adalah hash-nya.
 * Ini persis seperti konsep password: simpan hash-nya, bukan nilai aslinya.
 *
 * ---------------------------------------------------------------------------
 * KONSEP 2: Hash password (NFR-02)
 * ---------------------------------------------------------------------------
 * Password TIDAK PERNAH disimpan apa adanya. Di model User sudah ada
 * cast 'hashed' => ketika kita mengetik $user->password = 'rahasia',
 * Laravel otomatis menyimpannya sebagai hash bcrypt.
 *
 * Verifikasi memakai Hash::check('rahasia', $user->password) -> true/false.
 * Jangan pernah memakai == atau === untuk membandingkan password!
 *
 * ---------------------------------------------------------------------------
 * KONSEP 3: Role & Mass Assignment (erapu / privilege escalation)
 * ---------------------------------------------------------------------------
 * Role TIDAK pernah diambil dari request client. Kalau field "role" menerima
 * input dari user, orang bisa POST { role: "admin" } dan langsung jadi admin
 * (privilege escalation). Karena itu:
 *   - 'role' TIDAK ada di $fillable (Laravel Attribute Fillable).
 *   - Role ditetapkan oleh server saat registrasi: User::registerCitizen().
 *
 * Daftar role (lihat SRS Bab 1):
 *   citizen        -> masyarakat pelapor
 *   verifier_admin -> admin verifikator (membaca hasil tebakan AI, verifikasi/tolak)
 *   agency_admin   -> admin instansi (menindaklanjuti laporan terverifikasi)
 *
 * Tips efisiensi tim 2 orang dari SRS: verifier_admin & agency_admin boleh
 * digabung jadi satu role "admin" di UI, asalkan alur tombol tetap
 * Verifikasi -> Tindak Lanjut.
 */
class AuthController extends Controller
{
    /**
     * POST /api/auth/register
     *
     * Registrasi akun warga baru.
     *
     * Response:
     * {
     *   "success": true,
     *   "message": "Registrasi berhasil. Selamat datang!",
     *   "data": {
     *     "token": "5|xxxxxxxxxxxxxxxx",   <- token API (Bearer)
     *     "token_type": "Bearer",
     *     "user": { "id": 1, "name": "...", "email": "...", "role": "citizen" }
     *   }
     * }
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        // Panggil method pada model, bukan User::create([...]).
        // Di dalam method itu role dipaksa citizen dan password di-hash.
        $user = User::registerCitizen(
            name: $request->validated('name'),
            email: $request->validated('email'),
            password: $request->validated('password'),
        );

        // createToken() menghasilkan "Personal Access Token".
        // - plainTextToken : string token yang dikirim ke client (rahasia!)
        // - token yang disimpan di DB adalah hash dari string tersebut.
        $token = $user->createToken('auth-token')->plainTextToken;

        return $this->sendResponse([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => (new UserResource($user))->forViewer($user)->resolve($request),
        ], 'Registrasi berhasil. Selamat datang!', 201);
    }

    /**
     * POST /api/auth/login
     *
     * Login dengan email + password, mengembalikan token baru.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $password = $request->validated('password');

        // 1. Cari user berdasarkan email.
        $user = User::where('email', $email)->first();

        // 2. Cek password dengan Hash::check (wajib, bukan perbandingan string biasa).
        //    PENTING: user yang tidak ada DAN password salah menghasilkan pesan
        //    yang SAMA ("Email atau password salah") supaya penyerang tidak bisa
        //    menebak email mana yang terdaftar (user enumeration).
        if (! $user || ! Hash::check($password, $user->password)) {
            return $this->sendError('Email atau password salah.', 401);
        }

        // 3. Buat token baru. Setiap perangkat login mendapat token sendiri,
        //    sehingga logout di satu device tidak mematikan device lain.
        $token = $user->createToken('auth-token')->plainTextToken;

        return $this->sendResponse([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => (new UserResource($user))->forViewer($user)->resolve($request),
        ], 'Login berhasil.');
    }

    /**
     * POST /api/auth/logout
     *
     * Menghapus token yang sedang dipakai pada request ini.
     *
     * currentAccessToken() mengembalikan objek token milik request aktif.
     * Kalau user login dari banyak device, method ini hanya logout di
     * device ini. Untuk logout semua device, pakai $user->tokens()->delete().
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->sendResponse(null, 'Logout berhasil. Sampai jumpa!');
    }

    /**
     * GET /api/auth/me
     *
     * Mengambil profil user yang sedang login. Endpoint ini dipakai Next.js
     * untuk mengecek "apakah session masih valid?" saat membuka aplikasi -
     * ini yang disebut "cek token" / "restore session".
     */
    public function me(Request $request): JsonResponse
    {
        return $this->sendResponse(
            (new UserResource($request->user()))->resolve($request),
            'Profil berhasil dimuat.'
        );
    }
}
