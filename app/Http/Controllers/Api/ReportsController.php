<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Report\StoreReportRequest;
use App\Http\Resources\ReportDetailResource;
use App\Http\Resources\ReportsResource;
use App\Models\Reports;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function __construct(protected ReportService $reportService) {}

    public function public(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 10), 50);

        $reports = Reports::query()
            ->public()
            ->filter($request->only([
                'search', 'category_id', 'agency_id', 'status', 'priority', 'start_date', 'end_date',
            ]))
            ->with(['category', 'aiPredictedCategory', 'assignedAgency', 'user'])
            ->latest()
            ->paginate($perPage);

        return $this->sendResponse(
            ReportsResource::collection($reports->items())->resolve($request),
            'Daftar laporan publik berhasil dimuat.',
            200,
            ['meta' => $this->paginationMeta($reports)]
        );
    }

    public function my(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 10), 50);

        $reports = Reports::query()
            ->where('user_id', $request->user()->id)
            ->filter($request->only([
                'search', 'category_id', 'agency_id', 'status', 'priority', 'start_date', 'end_date',
            ]))
            ->with(['category', 'aiPredictedCategory', 'assignedAgency', 'user'])
            ->latest()
            ->paginate($perPage);

        return $this->sendResponse(
            ReportsResource::collection($reports->items())->resolve($request),
            'Daftar laporan Anda berhasil dimuat.',
            200,
            ['meta' => $this->paginationMeta($reports)]
        );
    }

    public function store(StoreReportRequest $request): JsonResponse
    {
        $report = $this->reportService->createFromCitizen(
            user: $request->user(),
            data: $request->safe()->except('image'),
            image: $request->file('image'),
        );

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

    public function show(Request $request, Reports $report): JsonResponse
    {
        $viewer = $request->user();
        $isOwner = $viewer !== null && $viewer->id === $report->user_id;
        $isAdmin = $viewer !== null && $viewer->isAdmin();

        // Admin instansi hanya boleh melihat laporan yang ditugaskan ke
        // instansinya. Ini yang membuat dashboard antar-dinas terisolasi.
        if ($viewer?->isAgencyAdmin()
            && ($viewer->agency_id === null
                || (int) $report->assigned_agency_id !== (int) $viewer->agency_id)) {
            return $this->sendError(
                'Laporan ini bukan bagian dari instansi Anda.',
                403
            );
        }

        $isPubliclyVisible = ! $report->is_private
            && in_array($report->status, [Reports::STATUS_VERIFIED, Reports::STATUS_FOLLOWED_UP], true);

        if (! $isOwner && ! $isAdmin && ! $isPubliclyVisible) {
            return $this->sendError(
                'Laporan ini tidak dapat diakses. Laporan privat hanya dapat dilihat oleh pelapor dan admin.',
                403
            );
        }

        $report->load(['category', 'aiPredictedCategory', 'assignedAgency', 'user', 'logs.changedBy']);

        return $this->sendResponse(
            (new ReportDetailResource($report))->resolve($request),
            'Detail laporan berhasil dimuat.'
        );
    }

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
