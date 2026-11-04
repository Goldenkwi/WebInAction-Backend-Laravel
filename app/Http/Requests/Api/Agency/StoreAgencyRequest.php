<?php

namespace App\Http\Requests\Api\Agency;

use App\Http\Requests\Api\ApiFormRequest;

/**
 * Validasi POST /api/admin/agencies (tambah instansi).
 *
 * Hanya admin yang boleh mengelola master data instansi. Route juga sudah
 * dilindungi middleware can:manage-agencies; authorize() di sini adalah
 * lapisan kedua supaya Form Request tetap aman dipakai ulang.
 */
class StoreAgencyRequest extends ApiFormRequest
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
            'name' => ['required', 'string', 'min:3', 'max:255', 'unique:agencies,name'],
            'acronym' => ['nullable', 'string', 'max:20', 'unique:agencies,acronym'],
            'description' => ['nullable', 'string', 'max:2000'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'sla_days' => ['nullable', 'integer', 'min:1', 'max:30'],
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
