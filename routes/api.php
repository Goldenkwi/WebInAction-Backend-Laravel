<?php

use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\AgencyController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\CategoriesController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\StatsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| File ini otomatis memakai prefix "/api" (lihat bootstrap/app.php).
| Jadi "Route::get('/categories')" dipanggil sebagai "GET /api/categories".
|
| ---------------------------------------------------------------------------
| CATATAN ROUTING UNTUK PEMBELAJARAN
| ---------------------------------------------------------------------------
| 1. Urutan route PENTING. Route dengan parameter (mis. /categories/{category})
|    harus diletakkan SETELAH route statis (mis. /reports/public).
|    Kalau dibalik, Laravel menganggap "public" sebagai nilai {report}.
|
| 2. Route Model Binding: nama placeholder di URL harus sama dengan nama
|    parameter di method controller.
|        URL    : /categories/{category}
|        Method : show(Categories $category)
|    Kalau namanya beda, Laravel tidak bisa menemukan modelnya.
|
| 3. Middleware urut dari kiri ke kanan dieksekusi berurutan.
|    auth:sanctum -> memastikan token valid (401 kalau tidak).
|    can:xxx      -> memastikan user punya hak (403 kalau tidak).
|    throttle:xxx -> membatasi frekuensi (429 kalau kebanyakan).
|
| 4. Placeholder {report} dibatasi whereNumber() supaya kata "public" atau
|    "my" tidak pernah dianggap sebagai id.
*/

// ===========================================================================
// MODUL AUTH (FR-AUTH-01)
// ===========================================================================

Route::prefix('auth')->group(function () {
    // Publik: daftar & login.
    Route::post('register', [AuthController::class, 'register']);

    // throttle:login = batasi 5x percobaan per menit (lihat AppServiceProvider).
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Wajib login. auth:sanctum membaca header "Authorization: Bearer <token>".
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// ===========================================================================
// MODUL KATEGORI (master data untuk label AI BERT)
// ===========================================================================

// Publik: siapa pun (termasuk tamu) boleh melihat daftar kategori.
Route::get('categories', [CategoriesController::class, 'index']);
Route::get('categories/{category}', [CategoriesController::class, 'show'])->whereNumber('category');

// Hanya Admin. Gate "manage-categories" didefinisikan di AppServiceProvider.
// Catatan: Form Request ALSO mengecek authorize(), jadi ada dua lapis proteksi.
Route::middleware(['auth:sanctum', 'can:manage-categories'])->group(function () {
    Route::post('categories', [CategoriesController::class, 'store']);
    Route::put('categories/{category}', [CategoriesController::class, 'update'])->whereNumber('category');
    Route::delete('categories/{category}', [CategoriesController::class, 'destroy'])->whereNumber('category');
});

// ===========================================================================
// MODUL INSTANSI / UNIT KERJA (master data untuk disposisi laporan)
// ===========================================================================

// Publik: dipakai untuk dropdown, badge instansi, dan halaman informasi.
// Default hanya instansi aktif; tambahkan ?active_only=0 untuk melihat semua.
Route::get('agencies', [AgencyController::class, 'index']);
Route::get('agencies/{agency}', [AgencyController::class, 'show'])->whereNumber('agency');

// Hanya Admin. Gate "manage-agencies" didefinisikan di AppServiceProvider.
Route::prefix('admin')->middleware(['auth:sanctum', 'can:manage-agencies'])->group(function () {
    Route::post('agencies', [AgencyController::class, 'store']);

    // Dua route update:
    //  - POST untuk multipart/form-data yang membawa file logo
    //  - PUT/PATCH untuk update JSON biasa
    Route::post('agencies/{agency}', [AgencyController::class, 'update'])->whereNumber('agency');
    Route::match(['put', 'patch'], 'agencies/{agency}', [AgencyController::class, 'update'])
        ->whereNumber('agency');

    Route::delete('agencies/{agency}', [AgencyController::class, 'destroy'])->whereNumber('agency');
});

// ===========================================================================
// MODUL LAPORAN (FR-REP, FR-AI, FR-PUB)
// ===========================================================================

// --- Route STATIS HARUS DULU (lihat catatan #1 di atas) ---
// Daftar laporan publik (BR-03). Publik / warga.
Route::get('reports/public', [ReportsController::class, 'public']);

// Laporan milik warga yang sedang login. Semua status.
Route::get('reports/my', [ReportsController::class, 'my'])
    ->middleware('auth:sanctum');

// Kirim laporan baru (citizen). throttle:submit-report = maks 20/jam per akun.
Route::post('reports', [ReportsController::class, 'store'])
    ->middleware(['auth:sanctum', 'throttle:submit-report']);

// Detail 1 laporan + timeline. Route dengan parameter (selalu paling akhir).
Route::get('reports/{report}', [ReportsController::class, 'show'])
    ->whereNumber('report')
    ->middleware('auth:sanctum');

// ===========================================================================
// MODUL ADMIN (FR-ADM)
// ===========================================================================

Route::prefix('admin')->middleware('auth:sanctum')->group(function () {
    // Tabel laporan untuk admin. butuh gate view-admin-reports.
    Route::get('reports', [AdminReportController::class, 'index'])
        ->middleware('can:view-admin-reports');

    // Verifikasi: pilih kategori final + memo penugasan -> verified.
    Route::patch('reports/{report}/verify', [AdminReportController::class, 'verify'])
        ->whereNumber('report')
        ->middleware('can:verify-reports');

    // Penolakan: isi alasan -> rejected.
    Route::patch('reports/{report}/reject', [AdminReportController::class, 'reject'])
        ->whereNumber('report')
        ->middleware('can:reject-reports');

    // Tindak lanjut: feedback + foto bukti -> followed_up.
    Route::post('reports/{report}/followup', [AdminReportController::class, 'followUp'])
        ->whereNumber('report')
        ->middleware('can:followup-reports');
});

// ===========================================================================
// MODUL STATISTIK (FR-STAT-01)
// ===========================================================================

Route::get('stats/summary', [StatsController::class, 'summary'])
    ->middleware('auth:sanctum');

// Route bawaan Laravel (biarkan saja, berguna untuk cek sesi token).
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
