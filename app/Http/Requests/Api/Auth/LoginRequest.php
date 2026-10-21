<?php

namespace App\Http\Requests\Api\Auth;

use App\Http\Requests\Api\ApiFormRequest;
use Illuminate\Support\Str;

/**
 * Validasi untuk endpoint POST /api/auth/login.
 *
 * Untuk login kita SENGAJA tidak memakai aturan "email:exists" di sini.
 * Alasannya: kalau email tidak terdaftar, dan kita membocorkan pesan
 * "email tidak terdaftar", itu menjadi informasi bagi penyerang (email guessing).
 *
 * Pola yang dipakai (dan umum di industri):
 *   - Validasi bentuk input saja di sini (email & password wajib diisi).
 *   - Pemeriksaan "user + password benar" dilakukan di Controller dengan
 *     satu pesan generik: "Email atau password salah."
 *
 * Rate limiting (pembatasan percobaan login) ada di routes/api.php
 * -> middleware 'throttle:login'.
 */
class LoginRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'password.required' => 'Password wajib diisi.',
        ];
    }

    /**
     * Sama seperti register: samakan format email agar konsisten.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => Str::lower(trim((string) $this->input('email'))),
            ]);
        }
    }
}
