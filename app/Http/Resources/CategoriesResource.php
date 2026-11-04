<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource untuk model Categories.
 *
 * ---------------------------------------------------------------------------
 * KONSEP: Kenapa perlu API Resource?
 * ---------------------------------------------------------------------------
 * 1. MENGATUR BENTUK OUTPUT. Model boleh punya kolom internal (created_at,
 *    updated_at, deleted_at) tapi frontend tidak perlu semuanya. Resource
 *    adalah "kontrak" yang bisa kamu ubah tanpa mengubah database.
 *
 * 2. MENYENSOR DATA. Tidak semua user berhak melihat semua field.
 *    Contoh di proyek kita: nama pelapor harus disembunyikan kalau
 *    laporan bersifat anonim (NFR-02).
 *
 * 3. KONSISTEN. Semua endpoint yang memuat kategori selalu mengembalikan
 *    bentuk JSON yang sama => frontend tidak perlu logika berbeda-bedakan.
 *
 * CARA PAKAI:
 *   CategoriesResource::collection($query)->resolve($request)  -> array
 *   (new CategoriesResource($model))->resolve($request)         -> array
 *
 * Catatan: resolve() dipakai supaya hasilnya bisa dibungkus Base Controller
 * menjadi envelope { success, message, data }. Kalau tidak di-resolve dan
 * langsung di-return, Laravel akan membungkusnya jadi { "data": { ... } }
 * yang bentrok dengan kontrak kita.
 */
class CategoriesResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon,

            // Instansi default yang menangani kategori ini.
            'default_agency_id' => $this->default_agency_id,
            'default_agency' => $this->whenLoaded('defaultAgency', fn () => $this->defaultAgency ? [
                'id' => $this->defaultAgency->id,
                'name' => $this->defaultAgency->name,
                'acronym' => $this->defaultAgency->acronym,
            ] : null),

            // whenCounted(): field ini HANYA muncul kalau query-nya memakai
            // withCount('reports'). Kalau tidak, field disembunyikan saja
            // (bukan null) supaya payload tetap ramping.
            'reports_count' => $this->whenCounted('reports'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
