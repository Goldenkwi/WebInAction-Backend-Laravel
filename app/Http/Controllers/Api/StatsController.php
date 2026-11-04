<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reports;
use App\Models\ReportsLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Statistik dashboard (FR-STAT-01 & FR-STAT-02).
 *
 * Angka yang dikembalikan mengikuti cakupan user yang login:
 *  - `verifier_admin` / citizen : seluruh sistem.
 *  - `agency_admin`             : hanya laporan dengan
 *    `reports.assigned_agency_id = users.agency_id`.
 *
 * Scoping ini penting karena dashboard instansi tidak boleh melihat angka
 * laporan dinas lain. `agency_admin` tanpa `agency_id` sengaja di-scope ke 0
 * supaya tidak jatuh ke "semua laporan".
 */
class StatsController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $agencyId = $this->agencyScopeId($request);
        $reports = $this->reportsQuery($agencyId);

        $total = (clone $reports)->count();
        $pending = (clone $reports)->where('status', Reports::STATUS_PENDING)->count();
        $verified = (clone $reports)->where('status', Reports::STATUS_VERIFIED)->count();
        $completed = (clone $reports)->where('status', Reports::STATUS_FOLLOWED_UP)->count();
        $rejected = (clone $reports)->where('status', Reports::STATUS_REJECTED)->count();

        return $this->sendResponse([
            'total_reports' => $total,
            'pending_reports' => $pending,
            'completed_reports' => $completed,
            'average_resolution_days' => $this->averageResolutionDays($agencyId),

            'verified_reports' => $verified,
            'rejected_reports' => $rejected,

            'by_status' => [
                ['status' => Reports::STATUS_PENDING,     'label' => 'Menunggu',        'count' => $pending],
                ['status' => Reports::STATUS_VERIFIED,    'label' => 'Terverifikasi',   'count' => $verified],
                ['status' => Reports::STATUS_FOLLOWED_UP, 'label' => 'Ditindaklanjuti', 'count' => $completed],
                ['status' => Reports::STATUS_REJECTED,    'label' => 'Ditolak',         'count' => $rejected],
            ],

            'by_category' => $this->byCategory($agencyId),

            'by_priority' => $this->byPriority($agencyId),
        ], 'Statistik dashboard berhasil dimuat.');
    }

    /**
     * ID instansi yang membatasi statistik, atau NULL untuk statistik global.
     *
     * `agency_admin` tanpa instansi memakai ID 0 (tidak ada laporan yang cocok)
     * agar tidak bocor menjadi statistik seluruh sistem.
     */
    protected function agencyScopeId(Request $request): ?int
    {
        $user = $request->user();

        if ($user?->isAgencyAdmin()) {
            return (int) ($user->agency_id ?? 0);
        }

        return null;
    }

    /**
     * Query dasar laporan yang sudah dibatasi ke instansi (kalau ada).
     */
    protected function reportsQuery(?int $agencyId): Builder
    {
        return Reports::query()->when(
            $agencyId !== null,
            fn (Builder $query) => $query->where('assigned_agency_id', $agencyId)
        );
    }

    protected function averageResolutionDays(?int $agencyId): float
    {
        $durations = ReportsLog::query()
            ->where('reports_logs.status', Reports::STATUS_FOLLOWED_UP)
            ->join('reports', 'reports.id', '=', 'reports_logs.report_id')
            ->when(
                $agencyId !== null,
                fn (Builder $query) => $query->where('reports.assigned_agency_id', $agencyId)
            )
            ->select([
                'reports.created_at as report_created_at',
                'reports_logs.created_at as log_created_at',
            ])
            ->get()
            ->map(function ($row) {
                $start = Carbon::parse($row->report_created_at);
                $end = Carbon::parse($row->log_created_at);

                // Selisih dalam hari, minimal 0 (kalog ada data aneh).
                return max(0, $start->diffInSeconds($end) / 86400);
            });

        if ($durations->isEmpty()) {
            return 0.0;
        }

        return round($durations->avg(), 2);
    }

    protected function byCategory(?int $agencyId): array
    {
        return $this->reportsQuery($agencyId)
            ->whereNotNull('category_id')
            ->with('category')
            ->get()
            ->groupBy('category_id')
            ->map(function ($items, $categoryId) {
                $category = $items->first()->category;

                return [
                    'category_id' => $categoryId,
                    'name' => $category?->name ?? 'Tidak Diketahui',
                    'icon' => $category?->icon,
                    'count' => $items->count(),
                ];
            })
            ->values()
            ->sortByDesc('count')
            ->all();
    }

    protected function byPriority(?int $agencyId): array
    {
        $labels = [
            Reports::PRIORITY_DARURAT => 'Darurat',
            Reports::PRIORITY_TINGGI => 'Tinggi',
            Reports::PRIORITY_SEDANG => 'Sedang',
            Reports::PRIORITY_RENDAH => 'Rendah',
        ];

        $counts = $this->reportsQuery($agencyId)
            ->selectRaw('priority, COUNT(*) as total')
            ->groupBy('priority')
            ->pluck('total', 'priority');

        return collect($labels)->map(function ($label, $value) use ($counts) {
            return [
                'priority' => $value,
                'label' => $label,
                'count' => (int) ($counts[$value] ?? 0),
            ];
        })->all();
    }
}
