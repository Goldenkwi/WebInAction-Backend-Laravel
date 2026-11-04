<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // -------------------------------------------------------------------
        // 1. GATE = OBJEK LAYANAN OTORISASI
        // -------------------------------------------------------------------
        // Gate mendefinisikan "nama kemampuan", lalu route memanggilnya
        // lewat middleware 'can:nama-kemampuan'.
        //
        // Contoh pemakaian di routes/api.php:
        //   Route::post('/categories', ...)->middleware('can:manage-categories');
        //
        // Keuntungan Gate dibanding cek manual di controller:
        //   - Aturan akses terpusat di satu file, mudah diaudit.
        //   - Tidak ada controller yang "lupa" cek hak akses.
        //   - User yang belum login otomatis dapat 403 (tidak bisa di-bypass
        //     hanya dengan menebak URL).
        //
        // Catatan: Gate::define hanya berlaku untuk user yang SUDAH terautentikasi.
        // Endpoint publik (mis. GET /api/categories) tidak diberi middleware can.
        Gate::define('manage-categories', function (User $user) {
            return $user->isAdmin();
        });

        // Master data instansi juga hanya boleh diubah admin.
        Gate::define('manage-agencies', function (User $user) {
            return $user->isAdmin();
        });

        // -------------------------------------------------------------------
        // 2. GATE MODUL LAPORAN
        // -------------------------------------------------------------------
        // SRS idealnya:
        //   verify / reject -> verifier_admin
        //   followup        -> agency_admin
        //
        // TAPI SRS juga memberi tips efisiensi untuk tim 2 orang: kedua peran
        // boleh digabung jadi satu akun admin, asalkan alur tombol tetap
        // Verifikasi -> Tindak Lanjut.
        //
        // Karena itu di sini KEDUA role admin diizinkan untuk semua aksi.
        // Demo tetap bisa memakai 2 akun terpisah (verifier + agency) ATAU
        // 1 akun admin untuk keduanya - tinggal pilih akun mana yang login.
        //
        // Kalau mau ketat persis SRS, ubah 3 closure di bawah jadi:
        //     return $user->role === User::ROLE_VERIFIER;   // untuk verify/reject
        //     return $user->role === User::ROLE_AGENCY;     // untuk followup
        Gate::define('view-admin-reports', function (User $user) {
            return $user->isAdmin();
        });

        Gate::define('verify-reports', function (User $user) {
            return $user->isAdmin();
        });

        Gate::define('reject-reports', function (User $user) {
            return $user->isAdmin();
        });

        Gate::define('followup-reports', function (User $user) {
            return $user->isAdmin();
        });

        // -------------------------------------------------------------------
        // 3. RATE LIMITER = PEMBATASAN PERCOBAAN
        // -------------------------------------------------------------------
        // Melindungi endpoint login dari serangan brute-force (menebak password).
        // Aturannya: maksimal 5 percobaan login per menit untuk kombinasi
        // alamat IP + email. Contoh: 1.2.3.4 mencoba 5x -> percobaan ke-6
        // otomatis ditolak dengan HTTP 429.
        //
        // Perhatikan: dibatasi per (IP + email), supaya satu orang yang salah
        // password tidak bisa mengunci seluruh kantor yang satu IP.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip().'|'.strtolower((string) $request->input('email')));
        });

        // Rate limiter untuk pengiriman laporan: mencegah spam dari satu akun
        // (mis. script otomatis mengirim ratusan laporan).
        RateLimiter::for('submit-report', function (Request $request) {
            return Limit::perHour(20)->by((string) $request->user()?->id);
        });
    }
}
