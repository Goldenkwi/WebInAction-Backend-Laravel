<?php

namespace App\Http\Requests\Api\Auth;

use App\Http\Requests\Api\ApiFormRequest;
use Illuminate\Support\Str;

/**
 * Validasi untuk endpoint POST /api/auth/register.
 *
 * Aturan main:
 * - name  : wajib, minimal 3 karakter (biar tidak "a" sebagai nama tampilan).
 * - email : wajib, format email, unik di tabel users.
 * - password: wajib, minimal 8 karakter, max 72 karakter.
 *
 * CATATAN PENTING soal max:72
 * Bcrypt hanya menyalin 72 byte pertama password. Jadi password lebih dari 72
 * karakter akan dipotong diam-diam (tidak error). Dengan membatasi max:72, user
 * dapat langsung diberi tahu kalau password-nya terlalu panjang.
 * Aturan ini berasal dari NFR-02 di SRS (password di-hash dengan bcrypt).
 */
class RegisterRequest extends ApiFormRequest
{
    /**
     * Anyone may register. Otorisasi "boleh daftar" itu sendiri yang dicek di sini.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Aturan validasi. Method ini dipanggil Laravel otomatis sebelum controller.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
            // Aturan "confirmed" otomatis membuat Laravel mencari field
            // "password_confirmation" dan membandingkannya dengan "password".
            // Field password_confirmation TIDAK perlu dideklarasikan di rules().
        ];
    }

    /**
     * Pesan error custom (bahasa manusia, untuk ditampilkan di Next.js).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama wajib diisi.',
            'name.min' => 'Nama minimal 3 karakter.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email ini sudah terdaftar. Silakan login.',
            'password.required' => 'Password wajib diisi.',
            'password.min' => 'Password minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak sama dengan password.',
        ];
    }

    /**
     * Menormalisasi input SEBELUM divalidasi (dipanggil Laravel otomatis).
     *
     * Email kita lowercase semua supaya "Budi@Mail.com" dan "budi@mail.com"
     * dianggap akun yang sama (database unik pada collation MySQL bisa saja
     * tetap membedakannya).
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
