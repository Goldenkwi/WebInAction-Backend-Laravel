<?php

namespace App\Http\Requests\Api\Report;

use App\Http\Requests\Api\ApiFormRequest;

/**
 * Validasi endpoint tindak lanjut laporan (FR-ADM-04).
 *
 * ---------------------------------------------------------------------------
 * BR-02: CATATAN FEEDBACK WAJIB
 * ---------------------------------------------------------------------------
 * Admin instansi wajib menuliskan catatan tindak lanjut. Ini yang akan dibaca
 * pelapor di halaman detail laporan-nya, jadi tidak boleh kosong.
 *
 * `proof_img` (foto bukti penyelesaian) OPSIONAL, tapi kalau dikirim harus
 * sesuai NFR-03: jpg/jpeg/png/webp, maksimal 2 MB.
 */
class FollowUpReportRequest extends ApiFormRequest
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
            'note' => ['required', 'string', 'min:10', 'max:2000'],
            'proof_img' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'Catatan tindak lanjut wajib diisi.',
            'note.min' => 'Catatan tindak lanjut minimal 10 karakter.',
            'proof_img.mimes' => 'Foto bukti harus berformat JPG, JPEG, PNG, atau WEBP.',
            'proof_img.max' => 'Ukuran foto bukti maksimal 2 MB.',
        ];
    }
}
