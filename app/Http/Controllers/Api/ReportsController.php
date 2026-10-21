<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Report\StoreReportRequest;
use App\Http\Resources\ReportDetailResource;
use App\Http\Resources\ReportsResource;
use App\Models\Reports;
use App\Services\ReportService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ============================================================================
 * REPORTS CONTROLLER  (FR-REP-01 s/d FR-REP-03, FR-AI-01 s/d FR-AI-03,
 *                     FR-PUB-01, FR-PUB-02, BR-03, NFR-02)
 * ============================================================================
 * Endpoint yang ditangani:
 *   GET  /api/reports/public -> daftar laporan publik   (Publik / Warga)
 *   GET  /api/reports/my     -> laporan milik saya       (citizen)
 *   POST /api/reports        -> kirim laporan baru       (citizen)
 *   GET  /api/reports/{id}   -> detail + timeline        (Semua yang login)
 *
 * CATATAN PENTING SOAL URUTAN ROUTE:
 * Di routes/api.php, route `reports/public` dan `reports/my` HARUS ditulis
 * SEBELUM route `reports/{report}`. Kalau dibalik, Laravel akan menganggap
 * kata "public" sebagai nilai {report} dan membawanya ke method show().
 */
class ReportsController extends Controller
{
    public function __construct(protected ReportService $reportService) {}

    /**
     * GET /api/reports/public
     *
     * Daftar laporan yang dipajang di halaman "Laporan Publik".
     *
     * BR-03: hanya laporan is_private = FALSE dan status verified/followed_up.
     * Laporan yang masih "Menunggu" atau "Ditolak" TIDAK muncul di sini.
     *
     * Parameter query (FR-PUB-01):
     *   ?search=         -> cari di judul
     *   ?category_id=    -> filter kategori
     *   ?status=         -> verified | followed_up
     *   ?start_date=     -> rentang tanggal (YYYY-MM-DD)
     *   ?end_date=
     *   ?per_page=       -> default 10, max 50
     */
    public function public(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 10), 50);

        $reports = Reports::query()
            ->public()
            ->filter($request->only([
                'search', 'category_id', 'status', 'priority', 'start_date', 'end_date',
            ]))
            ->with(['category', 'aiPredictedCategory', 'user'])
            ->latest()
            ->paginate($perPage);

        return $this->sendResponse(
            ReportsResource::collection($reports->items())->resolve($request),
            'Daftar laporan publik berhasil dimuat.',
            200,
            ['meta' => $this->paginationMeta($reports)]
        );
    }

    /**
     * GET /api/reports/my
     *
     * Daftar laporan milik warga yang sedang login - SEMUA status
     * (termasuk yang masih Menunggu, karena pelapor berhak memantau
     * laporannya sendiri yang belum diverifikasi).
     */
    public function my(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 10), 50);

        $reports = Reports::query()
            ->where('user_id', $request->user()->id)
            ->filter($request->only([
                'search', 'category_id', 'status', 'priority', 'start_date', 'end_date',
            ]))
            ->with(['category', 'aiPredictedCategory', 'user'])
            ->latest()
            ->paginate($perPage);

        return $this->sendResponse(
            ReportsResource::collection($reports->items())->resolve($request),
            'Daftar laporan Anda berhasil dimuat.',
            200,
            ['meta' => $this->paginationMeta($reports)]
        );
    }

    /**
     * POST /api/reports
     *
     * Warga mengirim laporan baru.
     *
     * Alur di dalam service (App\Services\ReportService):
     *   1. Simpan foto (kalau ada)
     *   2. Gabung title + description -> kirim ke AI Microservice
     *   3. Simpan laporan (status SELALU pending) + catat audit trail
     *
     * Kalau AI Microservice tidak tersedia, laporan TETAP tersimpan dengan
     * status pending (FR-AI-03 kondisi 3 / NFR-05). Form warga tidak pernah
     * gagal gara-gara AI.
     */
    public function store(StoreReportRequest $request): JsonResponse
    {
        $report = $this->reportService->createFromCitizen(
            user: $request->user(),
            data: $request->safe()->except('image'),
            image: $request->file('image'),
        );

        // Pesan menyesuaikan kondisi: kalau AI-nya tidak tersedia, jangan
        // berbohong pada warga ("telah dianalisis"). Ini FR-AI-03 kondisi 3.
        $message = $report->ai_confidence !== null
            ? 'Laporan berhasil dikirim dan telah dianalisis oleh sistem AI.'
            : 'Laporan berhasil dikirim. Modul AI sedang tidak tersedia, laporan menunggu verifikasi admin.';

        return $this->sendResponse([
            'id' => $report->id,
            'ticket_number' => $report->ticket_number,
            'title' => $report->title,
            'report_type' => $report->report_type,
            'status' => $report->status,
            'status_label' => $report->statusLabel(),
            'is_anonymous' => (bool) $report->is_anonymous,
            'is_private' => (bool) $report->is_private,
            'priority' => $report->priority,

            // Ringkasan hasil analisis AI untuk ditampilkan ke warga.
            'ai_analysis' => [
                'predicted_category_id' => $report->ai_pred_cat_id,
                'predicted_category_name' => $report->aiPredictedCategory?->name,
                'confidence_score' => $report->ai_confidence,
                'auto_disposed' => (bool) $report->category_id,
                'predicted_priority' => $report->ai_priority,
                'priority_confidence' => $report->ai_priority_confidence,
            ],

            'created_at' => $report->created_at?->toIso8601String(),
        ], $message, 201);
    }

    /**
     * GET /api/reports/{report}
     *
     * Detail lengkap satu laporan + timeline_logs (FR-PUB-02).
     *
     * ---------------------------------------------------------------------------
     * ATURAN AKSES (FR-REP-02)
     * ---------------------------------------------------------------------------
     * Laporan bisa dilihat oleh:
     *   - pelapor itu sendiri (selalu, semua status), atau
     *   - admin (semua laporan), atau
     *   - siapa pun, HANYA kalau laporan publication-Ready:
     *       is_private = false DAN status verified/followed_up
     *
     * Laporan privat yang masih "Menunggu" = hanya pelapor + admin yang bisa lihat.
     */
    public function show(Request $request, Reports $report): JsonResponse
    {
        $viewer = $request->user();
        $isOwner = $viewer !== null && $viewer->id === $report->user_id;
        $isAdmin = $viewer !== null && $viewer->isAdmin();

        $isPubliclyVisible = ! $report->is_private
            && in_array($report->status, [Reports::STATUS_VERIFIED, Reports::STATUS_FOLLOWED_UP], true);

        if (! $isOwner && ! $isAdmin && ! $isPubliclyVisible) {
            return $this->sendError(
                'Laporan ini tidak dapat diakses. Laporan privat hanya dapat dilihat oleh pelapor dan admin.',
                403
            );
        }

        $report->load(['category', 'aiPredictedCategory', 'user', 'logs.changedBy']);

        return $this->sendResponse(
            (new ReportDetailResource($report))->resolve($request),
            'Detail laporan berhasil dimuat.'
        );
    }

    /**
     * Metadata pagination untuk response.
     *
     * Ditaruh di key "meta" (di luar 3 kunci inti success/message/data)
     * supaya bentuk data tetap konsisten dengan modul lain.
     *
     * @param  LengthAwarePaginator  $paginator
     * @return array<string, mixed>
     */
    protected function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
