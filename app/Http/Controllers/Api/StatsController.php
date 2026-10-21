<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reports;
use App\Models\ReportsLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ============================================================================
 * STATS CONTROLLER  (FR-STAT-01)
 * ============================================================================
 * Endpoint: GET /api/stats/summary
 *
 * Mengembalikan 4 angka utama sesuai referensi "Aspirasi ITB":
 *   1. Rata-rata Waktu Penyelesaian (Hari)
 *   2. Total Laporan
 *   3. Laporan Masuk (Menunggu)
 *   4. Laporan Selesai (Ditindaklanjuti)
 *
 * ---------------------------------------------------------------------------
 * CARA MENGHITUNG RATA-RATA WAKTU PENYELESAIAN
 * ---------------------------------------------------------------------------
 * Waktu penyelesaian = selisih waktu laporan DIBUAT -> laporan mencapai
 * status `followed_up` (ditindaklanjuti).
 *
 * Diambil dari tabel `reports_logs` (baris log dengan status = followed_up),
 * karena `updated_at` di tabel reports bisa berubah karena hal lain.
 *
 * Perhitungan selisih dilakukan DI PHP (bukan SQL), supaya:
 *   - kodenya sama persis di MySQL maupun SQLite
 *   - tidak bergantung pada fungsi tanggal spesifik per database
 *     (julianday hanya ada di SQLite, DATEDIFF hanya di MySQL)
 */
class StatsController extends Controller
{
    /**
     * GET /api/stats/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $total = Reports::count();
        $pending = Reports::where('status', Reports::STATUS_PENDING)->count();
        $verified = Reports::where('status', Reports::STATUS_VERIFIED)->count();
        $completed = Reports::where('status', Reports::STATUS_FOLLOWED_UP)->count();
        $rejected = Reports::where('status', Reports::STATUS_REJECTED)->count();

        return $this->sendResponse([
            // --- 4 metrik utama (FR-STAT-01) ---
            'total_reports' => $total,
            'pending_reports' => $pending,
            'completed_reports' => $completed,
            'average_resolution_days' => $this->averageResolutionDays(),

            // --- Rincian tambahan untuk dashboard admin ---
            'verified_reports' => $verified,
            'rejected_reports' => $rejected,

            // Breakdown per status (untuk pie chart).
            'by_status' => [
                ['status' => Reports::STATUS_PENDING,     'label' => 'Menunggu',        'count' => $pending],
                ['status' => Reports::STATUS_VERIFIED,    'label' => 'Terverifikasi',   'count' => $verified],
                ['status' => Reports::STATUS_FOLLOWED_UP, 'label' => 'Ditindaklanjuti', 'count' => $completed],
                ['status' => Reports::STATUS_REJECTED,    'label' => 'Ditolak',         'count' => $rejected],
            ],

            // Breakdown per kategori.
            'by_category' => $this->byCategory(),

            // Breakdown per prioritas/urgensi.
            'by_priority' => $this->byPriority(),
        ], 'Statistik dashboard berhasil dimuat.');
    }

    /**
     * Rata-rata hari penyelesaian (dibulatkan 2 desimal).
     */
    protected function averageResolutionDays(): float
    {
        $durations = ReportsLog::query()
            // WAJIB qualify nama kolom: tabel reports_logs DAN reports sama-sama
            // punya kolom "status" & "created_at". Tanpa prefix, SQLite/MySQL
            // akan complaining "ambiguous column name".
            ->where('reports_logs.status', Reports::STATUS_FOLLOWED_UP)
            ->join('reports', 'reports.id', '=', 'reports_logs.report_id')
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

    /**
     * Jumlah laporan per kategori (hanya yang punya kategori).
     */
    protected function byCategory(): array
    {
        return Reports::query()
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

    /**
     * Jumlah laporan per prioritas.
     */
    protected function byPriority(): array
    {
        $labels = [
            Reports::PRIORITY_DARURAT => 'Darurat',
            Reports::PRIORITY_TINGGI => 'Tinggi',
            Reports::PRIORITY_SEDANG => 'Sedang',
            Reports::PRIORITY_RENDAH => 'Rendah',
        ];

        return collect($labels)->map(function ($label, $value) {
            return [
                'priority' => $value,
                'label' => $label,
                'count' => Reports::where('priority', $value)->count(),
            ];
        })->all();
    }
}
