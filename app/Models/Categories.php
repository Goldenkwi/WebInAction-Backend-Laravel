<?php

namespace App\Models;

use Database\Factories\CategoriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model Categories = master data kategori masalah.
 *
 * Dipakai sebagai DAFTAR LABEL untuk modul AI BERT (SRS FR-AI-01 & FR-AI-02):
 * teks laporan warga diklasifikasikan ke salah satu id kategori di tabel ini.
 *
 * Catatan penamaan: di ERD SRS tabelnya disebut "categories" dan model ini
 * memakai class "Categories" (mengikuti penamaan yang sudah ada di proyek ini).
 * Konvensi Laravel sendiri sebenarnya lebih menyarankan class singular
 * "Category" dengan tabel "categories".
 */
#[Fillable(['name', 'icon', 'ai_label_id', 'default_agency_id'])]
class Categories extends Model
{
    /** @use HasFactory<CategoriesFactory> */
    use HasFactory;

    /**
     * Laporan yang kategori akhirnya adalah kategori ini
     * (setelah diverifikasi admin).
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Reports::class, 'category_id');
    }

    /**
     * Instansi default yang menangani kategori ini.
     *
     * Dipakai sebagai rekomendasi disposisi saat admin memverifikasi laporan.
     * Admin tetap boleh memilih instansi lain di reports.assigned_agency_id.
     */
    public function defaultAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'default_agency_id');
    }

    /**
     * Laporan yang TEBAKAN AI-nya mengarah ke kategori ini.
     *
     * Penting: kolom ini dipakai untuk evaluasi akurasi model (SRS FR-ADM-03).
     * Kalau admin mengoreksi kategori, reports.category_id != reports.ai_pred_cat_id
     * dan selisihnya itulah data evaluasi yang dicari mentor/panitia.
     */
    public function aiPredictedReports(): HasMany
    {
        return $this->hasMany(Reports::class, 'ai_pred_cat_id');
    }

    /**
     * Scope pencarian (scope = method yang bisa dipakai di dalam query).
     *
     * Dipakai: Categories::search('infra')->get()
     *
     * Placeholder SQL (? ) dipakai supaya aman dari SQL Injection.
     */
    public function scopeSearch(Builder $query, ?string $keyword): Builder
    {
        if (blank($keyword)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($keyword) {
            $query->where('name', 'like', '%'.$keyword.'%')
                ->orWhere('icon', 'like', '%'.$keyword.'%');
        });
    }
}
