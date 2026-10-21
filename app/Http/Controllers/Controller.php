<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Base controller untuk seluruh endpoint API.
 *
 * -----------------------------------------------------------------------------
 * KONSEP 1: "JSON Envelope" (kontrak baku hasil balasan Laravel -> Next.js)
 * -----------------------------------------------------------------------------
 * Semua balasan API - sukses maupun gagal - memakai bentuk yang sama:
 *
 *   {
 *     "success": true | false,              // penanda hasil
 *     "message": "pesan dalam bahasa manusia", // dipakai untuk toast/alert di Next.js
 *     "data":    { ... } | [ ... ] | null,    // payload utama
 *     "errors":  { "field": ["pesan"] }       // OPSIONAL, hanya muncul saat validasi gagal
 *   }
 *
 * Kenapa perlu envelope (alasan teknis, bukan sekadar gaya):
 * 1. Next.js cukup memeriksa satu key (`success`) di satu tempat, tidak perlu
 *    menebak bentuk response untuk setiap endpoint.
 * 2. Error validasi (422), error otorisasi (403), dan error autentikasi (401)
 *    punya bentuk yang sama => frontend cukup menulis SATU fungsi handleError().
 * 3. Kontrak ini identik dengan contoh JSON di SRS Bab 4, jadi proposal/laporan
 *    dan kode selalu konsisten.
 *
 * -----------------------------------------------------------------------------
 * KONSEP 2: Kenapa helper-nya di Base Controller?
 * -----------------------------------------------------------------------------
 * Daripada menulis `response()->json([...])` berulang di setiap controller,
 * logika pembungkus envelope dipusatkan di sini (DRY). Controller fokus pada
 * "bisnis" (validasi, query, transformasi data), bukan pada "bungkus response".
 */
abstract class Controller
{
    /**
     * Bangun balasan API yang sukses.
     *
     * @param  mixed  $data  Payload utama (array, objek, JsonResource yang sudah di-resolve, atau null).
     * @param  string  $message  Pesan sukses untuk ditampilkan ke user.
     * @param  int  $code  HTTP status code (default 200).
     * @param  array<string, mixed>  $extra  Key tambahan di luar 3 kunci inti (mis. "meta" untuk pagination).
     */
    protected function sendResponse(mixed $data = null, string $message = 'Berhasil.', int $code = 200, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $extra), $code);
    }

    /**
     * Bangun balasan API yang gagal.
     *
     * Catatan: untuk error VALIDASI (422) dan error OTORISASI (403) yang
     * otomatis dibuat oleh Laravel, bentuk JSON-nya sudah diatur di:
     *   - app/Http/Requests/Api/ApiFormRequest.php  (failedValidation / failedAuthorization)
     *   - bootstrap/app.php                          (render exception 401/404)
     * Helper ini dipakai untuk error "bisnis" yang kita putuskan sendiri,
     * contoh: "Kategori masih dipakai laporan, tidak bisa dihapus".
     *
     * @param  array<string, array<int, string>>  $errors  Detail error per field.
     */
    protected function sendError(string $message = 'Terjadi kesalahan.', int $code = 400, array $errors = [], array $extra = []): JsonResponse
    {
        $payload = array_merge([
            'success' => false,
            'message' => $message,
            'data' => null,
        ], $extra);

        // "errors" hanya dikirim kalau ada isinya, supaya response tetap ringkas.
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $code);
    }
}
