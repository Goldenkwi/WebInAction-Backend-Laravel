<?php

namespace App\Models;

use Database\Factories\ReportsFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model Reports = tabel utama laporan masyarakat.
 *
 * ---------------------------------------------------------------------------
 * STRUKTUR DATA (mengikuti ERD 4 tabel di SRS + tambahan AI)
 * ---------------------------------------------------------------------------
 *  category_id            -> kategori hasil DISPOSISI (bisa diubah admin)
 *  ai_pred_cat_id         -> kategori TEBAKAN AI BERT (tidak diubah admin)
 *  ai_confidence          -> confidence score AI (0.00 - 1.00)
 *  ai_priority            -> level urgensi TEBAKAN AI (tidak diubah admin)
 *  ai_priority_confidence -> confidence score urgensi (0.00 - 1.00)
 *  priority               -> prioritas EFEKTIF (bisa dikoreksi admin)
 *
 * Perbedaan antara `category_id` dan `ai_pred_cat_id` adalah "data emas" untuk
 * evaluasi akurasi model (SRS FR-ADM-03). Kalau keduanya sama, tebakan AI benar.
 * Kalau berbeda, itu koreksi admin - yang justru data paling berharga.
 */
#[Fillable([
    'ticket_number',
    'user_id',
    'category_id',
    'ai_pred_cat_id',
    'assigned_agency_id',
    'ai_confidence',
    'ai_priority',
    'ai_priority_confidence',
    'priority',
    'report_type',
    'title',
    'description',
    'incident_date',
    'image_url',
    'location_text',
    'is_anonymous',
    'is_private',
    'status',
])]
class Reports extends Model
{
    /** @use HasFactory<ReportsFactory> */
    use HasFactory;

    /**
     * Empat status baku laporan (SRS Bab 2 bagian D).
     */
    public const STATUS_PENDING = 'pending';      // Menunggu

    public const STATUS_VERIFIED = 'verified';     // Terverifikasi

    public const STATUS_FOLLOWED_UP = 'followed_up';  // Ditindaklanjuti

    public const STATUS_REJECTED = 'rejected';     // Ditolak

    /**
     * Empat level prioritas/urgensi (output model urgency).
     */
    public const PRIORITY_RENDAH = 'rendah';

    public const PRIORITY_SEDANG = 'sedang';

    public const PRIORITY_TINGGI = 'tinggi';

    public const PRIORITY_DARURAT = 'darurat';

    /**
     * Urutan prioritas dari yang paling rendah - dipakai untuk sorting.
     */
    public const PRIORITY_ORDER = [
        self::PRIORITY_RENDAH => 1,
        self::PRIORITY_SEDANG => 2,
        self::PRIORITY_TINGGI => 3,
        self::PRIORITY_DARURAT => 4,
    ];

    /**
     * Cast atribut.
     *
     * PENTING: incident_date harus di-cast jadi 'date'. Tanpa itu, nilainya
     * tetap string mentah ("2026-10-16") di model, dan pemanggilan
     * ->toDateString() di Resource akan error.
     */
    protected function casts(): array
    {
        return [
            'incident_date' => 'date',
        ];
    }

    /**
     * Pelapor (warga) yang membuat laporan ini.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Kategori hasil disposisi akhir.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Categories::class, 'category_id');
    }

    /**
     * Kategori hasil tebakan AI BERT (untuk evaluasi akurasi).
     */
    public function aiPredictedCategory(): BelongsTo
    {
        return $this->belongsTo(Categories::class, 'ai_pred_cat_id');
    }

    /**
     * Instansi FINAL yang ditugaskan admin saat verifikasi.
     *
     * Berbeda dari categories.default_agency_id yang hanya rekomendasi:
     * admin bebas memilih instansi lain saat verifikasi.
     */
    public function assignedAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'assigned_agency_id');
    }

    /**
     * Riwayat/audit trail laporan ini (tabel reports_logs).
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ReportsLog::class, 'report_id');
    }

    /**
     * ---------------------------------------------------------------------------
     * BR-03: LAPORAN YANG BOLEH DIPAJANG DI HALAMAN PUBLIK
     * ---------------------------------------------------------------------------
     * Hanya laporan yang:
     *   - tidak privat (is_private = false), DAN
     *   - sudah berstatus `verified` atau `followed_up`
     *
     * Laporan yang masih `pending` atau `rejected` TIDAK dipajang, supaya
     * etalase publik bebas dari spam/hoaks (buku manual Civitas hal. 14).
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_private', false)
            ->whereIn('status', [self::STATUS_VERIFIED, self::STATUS_FOLLOWED_UP]);
    }

    /**
     * Filter generik untuk halaman daftar (FR-PUB-01).
     *
     * Mendukung: search (judul), category_id, agency_id, status, priority,
     * start_date & end_date (rentang tanggal pembuatan).
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                filled($filters['search'] ?? null),
                fn (Builder $q) => $q->where('title', 'like', '%'.trim($filters['search']).'%')
            )
            ->when(
                filled($filters['category_id'] ?? null),
                fn (Builder $q) => $q->where('category_id', (int) $filters['category_id'])
            )
            ->when(
                filled($filters['agency_id'] ?? null),
                fn (Builder $q) => $q->where('assigned_agency_id', (int) $filters['agency_id'])
            )
            ->when(
                filled($filters['status'] ?? null),
                fn (Builder $q) => $q->where('status', $filters['status'])
            )
            ->when(
                filled($filters['priority'] ?? null),
                fn (Builder $q) => $q->where('priority', $filters['priority'])
            )
            ->when(
                filled($filters['start_date'] ?? null),
                fn (Builder $q) => $q->whereDate('created_at', '>=', $filters['start_date'])
            )
            ->when(
                filled($filters['end_date'] ?? null),
                fn (Builder $q) => $q->whereDate('created_at', '<=', $filters['end_date'])
            );
    }

    /**
     * Label status dalam bahasa manusia (untuk badge di UI).
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Menunggu',
            self::STATUS_VERIFIED => 'Terverifikasi',
            self::STATUS_FOLLOWED_UP => 'Ditindaklanjuti',
            self::STATUS_REJECTED => 'Ditolak',
            default => ucfirst((string) $this->status),
        };
    }

    /**
     * Label prioritas dalam bahasa manusia.
     */
    public function priorityLabel(): ?string
    {
        return $this->priority ? ucfirst($this->priority) : null;
    }

    /**
     * Apakah tebakan AI sudah dikoreksi oleh admin?
     *
     * Ini bahan evaluasi akurasi model (SRS FR-ADM-03). Kalau true, berarti
     * admin tidak setuju dengan tebakan AI.
     */
    public function isAiCorrected(): bool
    {
        return $this->ai_pred_cat_id !== null
            && $this->category_id !== null
            && $this->ai_pred_cat_id !== $this->category_id;
    }
}
