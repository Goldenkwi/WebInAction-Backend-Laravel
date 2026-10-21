<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base Form Request untuk seluruh API.
 *
 * -----------------------------------------------------------------------------
 * APA ITU FORM REQUEST?
 * -----------------------------------------------------------------------------
 * Form Request adalah "gerbang" (gate) di depan controller:
 *
 *   Request  ---> [ ApiFormRequest ]  ---> Controller
 *                    |                    ^
 *                    |_- validasi gagal   |_- data sudah bersih & terotorisasi
 *                    |_- tidak berhak     |
 *                    |_- stop di sini     |
 *
 * Keuntungannya:
 * 1. Controller jadi ramping: tidak ada blok if ($request->has(...)) yang berantakan.
 * 2. Aturan validasi terkumpul di satu file per kasus (bisa dibaca auditor/mentor).
 * 3. Otorisasi otomatis: method authorize() menentukan siapa boleh lewat.
 *
 * -----------------------------------------------------------------------------
 * KENAPA KITA OVERRIDE failedValidation() & failedAuthorization()?
 * -----------------------------------------------------------------------------
 * Bawaan Laravel menghasilkan JSON seperti:
 *   { "message": "...", "errors": { "email": ["..."] } }
 * Bentuk ini TIDAK punya key "success" dan "data", jadi Next.js harus
 * ditangani berbeda dibanding response sukses & error biasa.
 *
 * Override di bawah memaksa SEMUA jalur error mengikuti envelope yang sama:
 *   { "success": false, "message": "...", "data": null, "errors": { ... } }
 */
abstract class ApiFormRequest extends FormRequest
{
    /**
     * Dipanggil otomatis oleh Laravel saat aturan validasi gagal.
     *
     * Kita TIDAK melakukan redirect (karena ini API), tapi melempar exception
     * HTTP yang isinya sudah berbentuk JSON envelope kita.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Data yang dikirim tidak valid. Periksa kembali isian Anda.',
            'data' => null,
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    /**
     * Dipanggil otomatis oleh Laravel saat user tidak boleh mengakses endpoint.
     */
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Anda tidak memiliki hak akses untuk tindakan ini.',
            'data' => null,
        ], 403));
    }
}
