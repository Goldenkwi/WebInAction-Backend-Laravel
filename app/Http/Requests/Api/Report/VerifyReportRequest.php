<?php

namespace App\Http\Requests\Api\Report;

use App\Http\Requests\Api\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi endpoint verifikasi laporan (FR-ADM-01).
 *
 * ---------------------------------------------------------------------------
 * BR-02: CATATAN WAJIB
 * ---------------------------------------------------------------------------
 * Admin TIDAK boleh memverifikasi laporan tanpa Memo Penugasan. Aturan
 * "wajib" di sini yang menegakkan BR-02 - bukan pengecekan manual di
 * controller. Kalau memo kosong, response 422 dan status TIDAK berubah.
 */
class VerifyReportRequest extends ApiFormRequest
{
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
            // Kategori final hasil keputusan admin (bisa berbeda dari tebakan AI).
            'category_id' => ['required', 'integer', 'exists:categories,id'],

            // Instansi final. Opsional: kalau kosong, sistem memakai
            // categories.default_agency_id. Admin tetap boleh memilih instansi
            // lain, jadi disposisi tidak terkunci pada 1 kategori = 1 instansi.
            'agency_id' => [
                'nullable',
                'integer',
                Rule::exists('agencies', 'id')->where('is_active', true),
            ],

            // Memo penugasan - wajib (BR-02).
            'note' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.required' => 'Pilih kategori tujuan penugasan.',
            'category_id.exists' => 'Kategori yang dipilih tidak ditemukan.',
            'agency_id.exists' => 'Instansi yang dipilih tidak ditemukan atau sedang nonaktif.',
            'note.required' => 'Memo penugasan wajib diisi.',
            'note.min' => 'Memo penugasan minimal 10 karakter.',
        ];
    }
}
