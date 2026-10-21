<?php

namespace App\Http\Requests\Api\Report;

use App\Http\Requests\Api\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi form pengiriman laporan (FR-REP-01).
 *
 * ---------------------------------------------------------------------------
 * ATURAN PENTING
 * ---------------------------------------------------------------------------
 * 1. `category_id` OPSIONAL.
 *    Warga sering tidak tahu kategori mana yang tepat - itu justru tugas AI.
 *    Kalau warga mengisinya, kategorinya dipakai sebagai "petunjuk" tapi AI
 *    tetap menganalisis. (SRS FR-REP-01)
 *
 * 2. `incident_date` WAJIB HANYA kalau report_type = "pengaduan".
 *   kalau "aspirasi", tanggal kejadian tidak relevan (aspirasi bisa soal
 *   -conditioning umum). Dicek dengan aturan `required_if`.
 *
 * 3. `image` OPSIONAL, format jpg/jpeg/png/webp, maksimal 2 MB (NFR-03).
 */
class StoreReportRequest extends ApiFormRequest
{
    /**
     * Hanya warga (citizen) yang boleh mengirim laporan.
     */
    public function authorize(): bool
    {
        return $this->user()?->isCitizen() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:10', 'max:150'],
            'report_type' => ['required', 'string', Rule::in(['pengaduan', 'aspirasi'])],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],

            // WAJIB hanya untuk pengaduan.
            'incident_date' => ['required_if:report_type,pengaduan', 'nullable', 'date'],

            'description' => ['required', 'string', 'min:20', 'max:5000'],
            'location_text' => ['required', 'string', 'min:5', 'max:255'],

            // NFR-03: hanya jpg/jpeg/png/webp, maks 2 MB.
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],

            // Toggle privasi & anonimitas (FR-REP-02).
            'is_anonymous' => ['sometimes', 'boolean'],
            'is_private' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Judul laporan wajib diisi.',
            'title.min' => 'Judul minimal 10 karakter agar mudah dipahami.',
            'report_type.required' => 'Pilih jenis laporan (pengaduan atau aspirasi).',
            'report_type.in' => 'Jenis laporan tidak valid.',
            'category_id.exists' => 'Kategori yang dipilih tidak ditemukan.',
            'incident_date.required_if' => 'Tanggal kejadian wajib diisi untuk jenis laporan pengaduan.',
            'incident_date.date' => 'Tanggal kejadian tidak valid.',
            'description.required' => 'Deskripsi laporan wajib diisi.',
            'description.min' => 'Deskripsi minimal 20 karakter. Ceritakan detail masalahnya.',
            'location_text.required' => 'Lokasi kejadian wajib diisi.',
            'image.mimes' => 'Foto harus berformat JPG, JPEG, PNG, atau WEBP.',
            'image.max' => 'Ukuran foto maksimal 2 MB.',
        ];
    }

    /**
     * Normalisasi input sebelum divalidasi.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => is_string($this->input('title')) ? trim($this->input('title')) : $this->input('title'),
            'description' => is_string($this->input('description')) ? trim($this->input('description')) : $this->input('description'),
            'location_text' => is_string($this->input('location_text')) ? trim($this->input('location_text')) : $this->input('location_text'),
        ]);
    }
}
