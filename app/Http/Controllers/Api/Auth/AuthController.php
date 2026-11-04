<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::registerCitizen(
            name: $request->validated('name'),
            email: $request->validated('email'),
            password: $request->validated('password'),
        );

        $token = $user->createToken('auth-token')->plainTextToken;

        return $this->sendResponse([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => (new UserResource($user))->forViewer($user)->resolve($request),
        ], 'Registrasi berhasil. Selamat datang!', 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $password = $request->validated('password');

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return $this->sendError('Email atau password salah.', 401);
        }

        $token = $user->createToken('auth-token')->plainTextToken;
        $user->loadMissing('agency');

        return $this->sendResponse([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => (new UserResource($user))->forViewer($user)->resolve($request),
        ], 'Login berhasil.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->sendResponse(null, 'Logout berhasil. Sampai jumpa!');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('agency');

        return $this->sendResponse(
            (new UserResource($user))->resolve($request),
            'Profil berhasil dimuat.'
        );
    }
}
