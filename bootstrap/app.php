<?php

use App\Exceptions\InvalidReportTransitionException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Semua request ke /api/* (atau yang meminta JSON) dirender sebagai JSON,
        // bukan halaman HTML error.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // -------------------------------------------------------------------
        // MENYATUKKAN BENTUK ERROR 401 (belum login / token tidak valid)
        // -------------------------------------------------------------------
        // Bawaan Laravel: { "message": "Unauthenticated." }
        // Tanpa ini, frontend harus menangani dua bentuk error berbeda.
        // Dengan ini, semua error mengikuti envelope {success, message, data}.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda belum login atau sesi Anda sudah berakhir. Silakan login kembali.',
                    'data' => null,
                ], 401);
            }
        });

        // -------------------------------------------------------------------
        // MENYATUKKAN BENTUK ERROR 403 (tidak punya hak akses)
        // -------------------------------------------------------------------
        // CATATAN PENTING (sudah diuji):
        // Middleware 'can:...' melempar Illuminate\Auth\Access\AuthorizationException.
        // Tapi Laravel MENGGANTIKAN exception itu menjadi
        // Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
        // SEBELUM handler render kustom dipanggil. Jadi kalau handler kita
        // mengetik "AuthorizationException", handler tersebut TIDAK AKAN
        // pernah dipanggil. Inilah penyebabnya, dicatat di sini supaya tidak
        // bingung kalau handler tidak pernah terpanggil.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki hak akses untuk tindakan ini.',
                    'data' => null,
                ], 403);
            }
        });

        // -------------------------------------------------------------------
        // MENYATUKKAN BENTUK ERROR 409 (transisi status laporan yang ilegal)
        // -------------------------------------------------------------------
        // BR-01: laporan hanya boleh bergerak maju (pending -> verified ->
        // followed_up, atau pending -> rejected). Melanggar aturan ini =
        // konflik dengan state resource saat ini => 409 Conflict.
        $exceptions->render(function (InvalidReportTransitionException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data' => null,
                ], $e->status());
            }
        });

        // -------------------------------------------------------------------
        // MENYATUKKAN BENTUK ERROR 429 (terlalu banyak permintaan)
        // -------------------------------------------------------------------
        // Dipicu oleh middleware throttle (login & submit-report).
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Terlalu banyak permintaan. Silakan coba beberapa saat lagi.',
                    'data' => null,
                ], 429);
            }
        });

        // -------------------------------------------------------------------
        // MENYATUKKAN BENTUK ERROR 404 (endpoint/data tidak ditemukan)
        // -------------------------------------------------------------------
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data yang diminta tidak ditemukan.',
                    'data' => null,
                ], 404);
            }
        });
    })->create();
