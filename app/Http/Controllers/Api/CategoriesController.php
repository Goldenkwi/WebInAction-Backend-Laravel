<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Category\StoreCategoryRequest;
use App\Http\Requests\Api\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoriesResource;
use App\Models\Categories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ============================================================================
 * CATEGORIES CONTROLLER
 * ============================================================================
 * Endpoint yang ditangani:
 *   GET    /api/categories            -> daftar kategori          (Publik)
 *   GET    /api/categories/{category} -> detail 1 kategori        (Publik)
 *   POST   /api/categories            -> tambah kategori          (Admin)
 *   PUT    /api/categories/{category} -> ubah kategori            (Admin)
 *   DELETE /api/categories/{category} -> hapus kategori           (Admin)
 *
 * ---------------------------------------------------------------------------
 * KONSEP 1: Controller = orkestrator tipis
 * ---------------------------------------------------------------------------
 * Urutan kerja satu request di dalam controller:
 *
 *   1. Middleware   : autentikasi (auth:sanctum) & otorisasi (can:manage-*)
 *   2. Form Request : validasi input + PENJAGAAN hak akses (authorize())
 *   3. Controller   : orchestrasi -> baca DB -> ubah data
 *   4. API Resource : shaping output (nama field & penyensoran data)
 *   5. Base Controller: pembungkus JSON envelope (success/message/data)
 *
 * Controller idealnya TIDAK berisi:
 *   - if/else validasi manual (pindah ke Form Request)
 *   - query SQL mentah (pakai Eloquent)
 *   - shaping JSON manual per field (pakai API Resource)
 *
 * ---------------------------------------------------------------------------
 * KONSEP 2: Mengapa kategori penting untuk proyek kita?
 * ---------------------------------------------------------------------------
 * `categories` adalah DAFTAR LABEL untuk modul AI BERT (SRS FR-AI-01/02).
 * Waktu warga mengirim laporan, teksnya dikirim ke service AI BERT, dan AI
 * menebak salah satu id kategori di tabel ini + confidence score.
 * Karena itu kategori:
 *   - HARUS unik  -> supaya tebakan AI tidak ambigu.
 *   - HAPUS Carefully: kalau kategori dihapus padahal sudah dipakai laporan,
 *     data historis jadi yatim (orphan). Karena itu destroy() mengecek
 *     apakah kategori masih dipakai.
 *
 * ---------------------------------------------------------------------------
 * KONSEP 3: Route Model Binding
 * ---------------------------------------------------------------------------
 * Method `show(Categories $category)` tidak perlu menulis
 * `Categories::find($request->id)` secara manual. Laravel melihat:
 *   - parameter method bernama `$category`
 *   - tipe datanya `Categories` (sebuah Model Eloquent)
 * maka otomatis mencari data categories dengan id dari URL, lalu berhenti
 * dengan 404 kalau tidak ditemukan.
 * Routing di routes/api.php harus memakai placeholder {category} agar
 * nama parameter route dan nama parameter method sama.
 */
class CategoriesController extends Controller
{
    /**
     * GET /api/categories
     *
     * Daftar kategori + filter sederhana.
     *
     * Parameter query (opsional):
     *   ?search=infra      -> cari berdasarkan nama kategori (case-insensitive)
     *   ?with_counts=1     -> sertakan jumlah laporan pada setiap kategori
     *
     * Catatan desain: kategori hanya ada handful (6-ish), jadi TIDAK kita
     * paginate di sini - frontend butuh daftar penuh untuk mengisi dropdown.
     * Untuk daftar laporan (yang bisa ribuan baris) nanti-lah pakai paginate.
     */
    public function index(Request $request): JsonResponse
    {
        $categories = Categories::query()
            // when($kondisi, $callback): menjalankan $callback HANYA kalau
            // $kondisi true. Ini membuat query string dinamis tetap rapi
            // tanpa-if yang bercabang.
            ->when(
                $request->filled('search'),
                fn ($query) => $query->search((string) $request->input('search'))
            )
            ->when(
                $request->boolean('with_counts'),
                fn ($query) => $query->withCount('reports')
            )
            // Master data selalu diurutkan agar tampilan stabil di semua device.
            ->orderBy('name')
            ->get();

        // Resource::collection(...)->resolve($request) mengubah Resource
        // menjadi array biasa, supaya bisa dibungkus sendResponse() di Base
        // Controller dan menghasilkan envelope yang konsisten.
        return $this->sendResponse(
            CategoriesResource::collection($categories)->resolve($request),
            'Daftar kategori berhasil dimuat.'
        );
    }

    /**
     * GET /api/categories/{category}
     *
     * Detail satu kategori. Dipakai Next.js untuk halaman "detail kategori"
     * atau saat admin ingin melihat berapa laporan yang pernah masuk kategori ini.
     */
    public function show(Request $request, Categories $category): JsonResponse
    {
        // withCount dipakai agar field "reports_count" muncul di dalam Resource.
        $category->loadCount('reports');

        return $this->sendResponse(
            (new CategoriesResource($category))->resolve($request),
            'Detail kategori berhasil dimuat.'
        );
    }

    /**
     * POST /api/categories
     *
     * Tambah kategori baru.
     *
     * @param  StoreCategoryRequest  $request  Sudah divalidasi oleh Laravel.
     *                                         $request->validated() berisi HANYA
     *                                         field yang lolos aturan + ada di rules.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        // $request->validated() aman dipakai langsung di mass-assignment:
        // field tambahan yang dikirim CLIENT (mis. "id" atau "created_by")
        // tidak ikut terbawa karena tidak ada di rules.
        $category = Categories::create($request->validated());

        return $this->sendResponse(
            (new CategoriesResource($category))->resolve($request),
            'Kategori berhasil ditambahkan.',
            201 // 201 Created = resource baru berhasil dibuat (bukan 200)
        );
    }

    /**
     * PUT /api/categories/{category}
     *
     * Ubah kategori. Method ini menerima PATCH semantics: field yang tidak
     * dikirim tidak akan diubah (berkat aturan "sometimes").
     */
    public function update(UpdateCategoryRequest $request, Categories $category): JsonResponse
    {
        // update() hanya mengubah kolom yang ada di $fillable model.
        $category->update($request->validated());

        return $this->sendResponse(
            (new CategoriesResource($category->fresh()))->resolve($request),
            'Kategori berhasil diperbarui.'
        );
    }

    /**
     * DELETE /api/categories/{category}
     *
     * Hapus kategori, TAPI hanya kalau belum dipakai laporan apa pun.
     *
     * Kenapa ada penjaga ini?
     * Field reports.category_id punya foreign key ke categories. Menghapus
     * kategori yang sudah dipakai akan:
     *   - menggagalkan delete (DB error, 500) kalau FK-nyarestrict, atau
     *   - menghapus/mengosongkan data laporan historis (data loss).
     * Jadi lebih baik ditolak dengan pesan jelas (409 Conflict) daripada
     * gagal diam-diam di level database.
     */
    public function destroy(Categories $category): JsonResponse
    {
        if ($category->reports()->exists()) {
            return $this->sendError(
                'Kategori masih digunakan oleh laporan sehingga tidak dapat dihapus. '
                .'Nonaktifkan kategori atau pindahkan laporan ke kategori lain.',
                409 // 409 Conflict = request bentrok dengan state resource saat ini
            );
        }

        $category->delete();

        return $this->sendResponse(null, 'Kategori berhasil dihapus.');
    }
}
