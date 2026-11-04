<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    protected function sendResponse(mixed $data = null, string $message = 'Berhasil.', int $code = 200, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $extra), $code);
    }

    protected function sendError(string $message = 'Terjadi kesalahan.', int $code = 400, array $errors = [], array $extra = []): JsonResponse
    {
        $payload = array_merge([
            'success' => false,
            'message' => $message,
            'data' => null,
        ], $extra);

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $code);
    }
}
