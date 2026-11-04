<?php

namespace App\Http\Requests\Api\Agency;

use App\Http\Requests\Api\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi ubah instansi.
 *
 * Route update sengaja mendukung POST (selain PUT/PATCH) karena upload file
 * lewat multipart/form-data lebih aman dikirim sebagai POST di PHP.
 */
class UpdateAgencyRequest extends ApiFormRequest
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
        $agencyId = $this->route('agency')?->id;

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'min:3',
                'max:255',
                Rule::unique('agencies', 'name')->ignore($agencyId),
            ],
            'acronym' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                Rule::unique('agencies', 'acronym')->ignore($agencyId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'logo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sla_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama instansi wajib diisi.',
            'name.unique' => 'Nama instansi tersebut sudah terdaftar.',
            'acronym.unique' => 'Singkatan instansi tersebut sudah dipakai.',
            'logo.image' => 'Logo harus berupa file gambar.',
            'logo.mimes' => 'Logo harus berformat JPG, JPEG, PNG, atau WEBP.',
            'logo.max' => 'Ukuran logo maksimal 2 MB.',
            'contact_email.email' => 'Format email instansi tidak valid.',
            'sla_days.min' => 'Target SLA minimal 1 hari.',
            'sla_days.max' => 'Target SLA maksimal 30 hari.',
        ];
    }
}
