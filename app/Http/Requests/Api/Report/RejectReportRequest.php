<?php

namespace App\Http\Requests\Api\Report;

use App\Http\Requests\Api\ApiFormRequest;

/**
 * Validasi endpoint penolakan laporan (FR-ADM-02).
 *
 * BR-02: Alasan penolakan WAJIB diisi. Tanpa alasan, pelapor tidak akan
 * pernah tahu kenapa laporannya ditolak.
 */
class RejectReportRequest extends ApiFormRequest
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'Alasan penolakan wajib diisi.',
            'note.min' => 'Alasan penolakan minimal 10 karakter. Jelaskan dengan jelas agar pelapor memahami.',
        ];
    }
}
