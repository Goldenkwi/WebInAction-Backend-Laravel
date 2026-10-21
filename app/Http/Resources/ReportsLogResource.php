<?php

namespace App\Http\Resources;

use App\Models\Reports;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource untuk baris audit trail (tabel reports_logs).
 *
 * ---------------------------------------------------------------------------
 * PERAN BARIS LOG DALAM ALUR
 * ---------------------------------------------------------------------------
 * Log pertama  : status `pending`    -> dibuat oleh SISTEM AI (changed_by = null)
 * Log kedua    : status `verified`   -> Memo Penugasan dari Admin Verifikator
 * Log ketiga   : status `followed_up` -> Feedback + foto bukti dari Admin Instansi
 * (atau `rejected` -> Alasan Penolakan)
 *
 * Karena `changed_by` NULL untuk log sistem, frontend perlu fallback label.
 * Kita pakai "Sistem AI BERT" - sama seperti contoh JSON di SRS Bab 4.
 */
class ReportsLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),

            'note' => $this->note,

            // Nama pelaku perubahan. NULL = sistem AI.
            'changed_by_name' => $this->changedBy?->name ?? 'Sistem AI BERT',
            'changed_by_role' => $this->changedBy?->role,

            // Foto bukti penyelesaian (untuk log tindak lanjut).
            'proof_img' => $this->proof_img,

            'created_at' => $this->created_at?->toIso8601String(),

            // Format tanggal Indonesia untuk timeline di UI.
            'created_at_label' => $this->created_at
                ?->locale('id')
                ->translatedFormat('d M Y, H:i'),
        ];
    }

    /**
     * Label status yang mudah dibaca.
     */
    protected function statusLabel(): string
    {
        return match ($this->status) {
            Reports::STATUS_PENDING => 'Laporan Diterima',
            Reports::STATUS_VERIFIED => 'Laporan Diverifikasi',
            Reports::STATUS_FOLLOWED_UP => 'Laporan Ditindaklanjuti',
            Reports::STATUS_REJECTED => 'Laporan Ditolak',
            default => ucfirst((string) $this->status),
        };
    }
}
