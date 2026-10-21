<?php

namespace App\Models;

use Database\Factories\ReportsLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Model ReportsLog = audit trail (NFR-04: tabel append-only).
 *
 * Setiap kali status laporan berubah, satu baris log dibuat berisi:
 *   - status  : status baru (pending/verified/followed_up/rejected)
 *   - note    : Memo Penugasan / Alasan Penolakan / Feedback Tindak Lanjut
 *   - proof_img : foto bukti penyelesaian (opsional)
 *
 * ---------------------------------------------------------------------------
 * KONSEP: APPEND-ONLY (NFR-04)
 * ---------------------------------------------------------------------------
 * "Append-only" berarti: baris log boleh ditambah, TIDAK boleh diubah atau
 * dihapus oleh pengguna biasa. Implementasinya di bawah: event model
 * 'updating' dan 'deleting' melempar exception.
 *
 * Kenapa penting? Log ini adalah BUKTI jejak digital (audit trail). Kalau
 * admin bisa menghapus log, makahistory bisa dimanipulasi dan nilainya
 * sebagai bukti hukum hilang.
 *
 * Catatan: cascade delete dari tabel reports tetap bisa terjadi (itu operasi
 * level database, bukan lewat model), jadi tidak bentrok dengan aturan ini.
 */
#[Fillable(['report_id', 'changed_by', 'status', 'note', 'proof_img'])]
class ReportsLog extends Model
{
    /** @use HasFactory<ReportsLogFactory> */
    use HasFactory;

    /**
     * Kunci append-only.
     */
    protected static function booted(): void
    {
        static::updating(function (ReportsLog $log) {
            throw new LogicException(
                'Log laporan bersifat append-only dan tidak boleh diubah.'
            );
        });

        static::deleting(function (ReportsLog $log) {
            throw new LogicException(
                'Log laporan bersifat append-only dan tidak boleh dihapus.'
            );
        });
    }

    /**
     * Laporan yang memiliki log ini.
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Reports::class, 'report_id');
    }

    /**
     * Admin yang melakukan perubahan status.
     *
     * Untuk log yang dibuat otomatis oleh sistem AI, kolom ini NULL dan
     * frontend menampilkannya sebagai "Sistem AI BERT".
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
