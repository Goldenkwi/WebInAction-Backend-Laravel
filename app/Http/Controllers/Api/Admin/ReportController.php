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

class ReportController extends Controller
{
    public function __construct(protected ReportService $reportService) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 15), 50);

        $reports = Reports::query()
            ->filter($request->only([
                'search', 'category_id', 'agency_id', 'status', 'priority', 'start_date', 'end_date',
            ]))
            ->when(
                $request->user()->isAgencyAdmin(),
                fn ($query) => $query->where(
                    'assigned_agency_id',
                    $request->user()->agency_id ?? 0
                )
            )
            ->with(['category', 'aiPredictedCategory', 'assignedAgency', 'user'])
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

    public function verify(VerifyReportRequest $request, Reports $report): JsonResponse
    {
        $report = $this->reportService->verify(
            report: $report,
            admin: $request->user(),
            categoryId: (int) $request->validated('category_id'),
            note: $request->validated('note'),
            agencyId: $request->validated('agency_id') !== null
                ? (int) $request->validated('agency_id')
                : null,
        );

        return $this->sendResponse(
            (new ReportDetailResource($report))->resolve($request),
            'Laporan berhasil diverifikasi dan ditugaskan.'
        );
    }

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
