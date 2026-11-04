<?php

namespace App\Http\Requests\Api\Report;

use App\Http\Requests\Api\ApiFormRequest;
use App\Models\Reports;
use App\Models\User;

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
        $user = $this->user();

        if (! $user?->isAdmin()) {
            return false;
        }

        // Admin instansi hanya boleh menindaklanjuti laporan yang ditugaskan
        // ke instansinya sendiri. Verifikator tetap boleh melihat/menangani
        // semua laporan karena tugasnya melakukan disposisi lintas instansi.
        if ($user->role === User::ROLE_AGENCY) {
            $report = $this->route('report');

            return $report instanceof Reports
                && $user->agency_id !== null
                && (int) $report->assigned_agency_id === (int) $user->agency_id;
        }

        return true;
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
