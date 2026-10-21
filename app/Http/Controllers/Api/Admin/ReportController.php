<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Report\FollowUpReportRequest;
use App\Http\Requests\Api\Report\RejectReportRequest;
use App\Http\Requests\Api\Report\VerifyReportRequest;
use App\Http\Resources\ReportDetailResource;
use App\Http\Resources\ReportsResource;
use App\Models\Reports;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ============================================================================
 * ADMIN REPORT CONTROLLER  (FR-ADM-01 s/d FR-ADM-04, FR-PUB-01)
 * ============================================================================
 * Endpoint yang ditangani:
 *   GET   /api/admin/reports                  -> tabel laporan untuk admin
 *   PATCH /api/admin/reports/{id}/verify      -> verifikasi + memo     (verified)
 *   PATCH /api/admin/reports/{id}/reject      -> tolak + alasan         (rejected)
 *   POST  /api/admin/reports/{id}/followup    -> tindak lanjut + feedback (followed_up)
 *
 * ---------------------------------------------------------------------------
 * HAK AKSES
 * ---------------------------------------------------------------------------
 * Idealnya (SRS tabel endpoint):
 *   verify / reject -> verifier_admin
 *   followup        -> agency_admin
 *
 * Tapi SRS juga memberi tips efisiensi untuk tim 2 orang: kedua peran boleh
 * digabung jadi satu akun admin, asalkan alur tombol tetap
 * Verifikasi -> Tindak Lanjut. Karena itu gate di sini MENGIJINKAN kedua
 * role untuk semua aksi. Kalau ingin ketat persis SRS, ubah definisi gate
 * di AppServiceProvider (lihat komentar di sana).
 *
 * ---------------------------------------------------------------------------
 * CATATAN PENTING SOAL AI
 * ---------------------------------------------------------------------------
 * Di halaman admin, admin HARUS bisa melihat:
 *   - tebakan kategori AI + confidence-nya
 *   - kategori mana yang akhirnya dipilih admin
 *   - apakah admin mengoreksi tebakan AI (FR-ADM-03)
 *
 * Info itu sudah otomatis ikut di ReportsResource lewat key "ai_analysis".
 */
class ReportController extends Controller
{
    public function __construct(protected ReportService $reportService) {}

    /**
     * GET /api/admin/reports
     *
     * Tabel lengkap laporan untuk admin, dengan filter:
     *   ?status=pending|verified|followed_up|rejected
     *   ?category_id=
     *   ?priority=rendah|sedang|tinggi|darurat
     *   ?search=        (judul)
     *   ?start_date= & ?end_date=
     *   ?per_page=      (default 15, max 50)
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 15), 50);

        $reports = Reports::query()
            ->filter($request->only([
                'search', 'category_id', 'status', 'priority', 'start_date', 'end_date',
            ]))
            ->with(['category', 'aiPredictedCategory', 'user'])
            // Prioritas tertinggi di atas, lalu yang terbaru.
            ->orderByRaw(
                "CASE priority WHEN 'darurat' THEN 4 WHEN 'tinggi' THEN 3 "
                ."WHEN 'sedang' THEN 2 WHEN 'rendah' THEN 1 ELSE 0 END DESC"
            )
            ->latest()
            ->paginate($perPage);

        return $this->sendResponse(
            ReportsResource::collection($reports->items())->resolve($request),
            'Daftar laporan berhasil dimuat.',
            200,
            ['meta' => [
                'current_page' => $reports->currentPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
                'last_page' => $reports->lastPage(),
            ]]
        );
    }

    /**
     * PATCH /api/admin/reports/{report}/verify
     *
     * FR-ADM-01: admin memverifikasi laporan, memilih kategori final, dan
     * menulis MEMO PENUGASAN. Status -> `verified`.
     *
     * FR-ADM-03: kalau kategori yang dipilih admin BERBEDA dari tebakan AI,
     * keduanya tetap tersimpan (category_id vs ai_pred_cat_id) - itu bahan
     * evaluasi akurasi model.
     */
    public function verify(VerifyReportRequest $request, Reports $report): JsonResponse
    {
        $report = $this->reportService->verify(
            report: $report,
            admin: $request->user(),
            categoryId: (int) $request->validated('category_id'),
            note: $request->validated('note'),
        );

        return $this->sendResponse(
            (new ReportDetailResource($report))->resolve($request),
            'Laporan berhasil diverifikasi dan ditugaskan.'
        );
    }

    /**
     * PATCH /api/admin/reports/{report}/reject
     *
     * FR-ADM-02: admin menolak laporan dengan ALASAN. Status -> `rejected`.
     */
    public function reject(RejectReportRequest $request, Reports $report): JsonResponse
    {
        $report = $this->reportService->reject(
            report: $report,
            admin: $request->user(),
            note: $request->validated('note'),
        );

        return $this->sendResponse(
            (new ReportDetailResource($report))->resolve($request),
            'Laporan telah ditolak dan alasannya tercatat.'
        );
    }

    /**
     * POST /api/admin/reports/{report}/followup
     *
     * FR-ADM-04: admin instansi menindaklanjuti laporan yang sudah terverifikasi.
     * Mengisi CATATAN FEEDBACK + mengunggah foto bukti (opsional).
     * Status -> `followed_up`.
     */
    public function followUp(FollowUpReportRequest $request, Reports $report): JsonResponse
    {
        $report = $this->reportService->followUp(
            report: $report,
            admin: $request->user(),
            note: $request->validated('note'),
            proof: $request->file('proof_img'),
        );

        return $this->sendResponse(
            (new ReportDetailResource($report))->resolve($request),
            'Tindak lanjut berhasil dicatat. Terima kasih atas kesediaan menangani.'
        );
    }
}
