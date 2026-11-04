<?php

namespace App\Models;

use Database\Factories\AgencyFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Model Agency = master data instansi/unit kerja.
 *
 * Relasi utama:
 *  - categories() : kategori yang instansi ini tangani secara default
 *  - reports()    : laporan yang sudah ditugaskan ke instansi ini
 *  - users()      : akun petugas (agency_admin) instansi ini
 *
 * `logo_url` menyimpan path relatif di disk `public` (mis.
 * "agencies/dpupr.png"). Accessor `logo_full_url` mengubahnya menjadi URL
 * lengkap supaya frontend tidak perlu merakit URL sendiri.
 */
#[Fillable([
    'name',
    'acronym',
    'description',
    'logo_url',
    'contact_email',
    'contact_phone',
    'address',
    'sla_days',
    'is_active',
])]
#[Appends(['logo_full_url'])]
class Agency extends Model
{
    /** @use HasFactory<AgencyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sla_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Kategori yang secara default ditangani instansi ini.
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Categories::class, 'default_agency_id');
    }

    /**
     * Laporan yang sudah ditugaskan ke instansi ini.
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Reports::class, 'assigned_agency_id');
    }

    /**
     * Akun petugas yang bertugas di instansi ini.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'agency_id');
    }

    /**
     * URL logo lengkap untuk frontend.
     *
     * Kalau data lama/eksternal sudah berupa URL penuh, kembalikan apa adanya.
     * Kalau masih path relatif, ubah lewat disk `public` sehingga hasilnya
     * mengikuti APP_URL (mis. http://localhost:8000/storage/agencies/logo.png).
     */
    protected function logoFullUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (blank($this->logo_url)) {
                return null;
            }

            if (filter_var($this->logo_url, FILTER_VALIDATE_URL)) {
                return $this->logo_url;
            }

            return Storage::disk('public')->url($this->logo_url);
        });
    }

    /**
     * Scope instansi yang masih aktif (untuk dropdown publik).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope pencarian nama/singkatan instansi.
     */
    public function scopeSearch(Builder $query, ?string $keyword): Builder
    {
        if (blank($keyword)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($keyword): void {
            $query->where('name', 'like', '%'.$keyword.'%')
                ->orWhere('acronym', 'like', '%'.$keyword.'%');
        });
    }
}
