<?php

namespace App\Http\Requests\Api\Category;

use App\Http\Requests\Api\ApiFormRequest;

/**
 * Validasi untuk endpoint POST /api/categories (tambah kategori baru).
 *
 * Kategori adalah "master data" yang dipakai modul AI BERT sebagai label
 * prediksi (lihat SRS FR-AI-01 & FR-AI-02). Karena itu nama kategori harus
 * unik dan stabil - tidak boleh ada "Infrastruktur" dan "infrastruktur" terpisah.
 */
class StoreCategoryRequest extends ApiFormRequest
{
    /**
     * Endpoint ini hanya untuk Admin (verifier_admin / agency_admin).
     * Penjaga sebenarnya ada di route -> middleware('can:manage-categories'),
     * tapi authorize() tetap kita isi supaya Form Request aman dipanggil sendiri.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:100', 'unique:categories,name'],
            'icon' => ['nullable', 'string', 'max:50'],
            'default_agency_id' => ['nullable', 'integer', 'exists:agencies,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama kategori wajib diisi.',
            'name.unique' => 'Kategori dengan nama tersebut sudah ada.',
            'default_agency_id.exists' => 'Instansi default yang dipilih tidak ditemukan.',
        ];
    }
}
