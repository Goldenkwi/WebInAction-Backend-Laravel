<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tes untuk modul Auth.
 *
 * Cara menjalankan:
 *   php artisan test --filter=AuthTest
 *
 * Konsep penting:
 * - RefreshDatabase : database dibersihkan & diisi ulang sebelum tiap test.
 * - actingAs()      : login user tanpa Throughly proses token (memudahkan test
 *                     endpoint yang butuh autentikasi).
 * - Sanctum::actingAs() dipakai kalau mau meniru login via token.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Registrasi berhasil: status 201, dapat token, role otomatis citizen.
     */
    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'citizen')
            ->assertJsonPath('data.user.email', 'budi@example.com')
            ->assertJsonStructure(['success', 'message', 'data' => ['token', 'token_type', 'user' => ['id', 'name', 'email', 'role']]]);

        // Token harus benar-benar tersimpan di database.
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => User::where('email', 'budi@example.com')->first()->id,
        ]);
    }

    /**
     * Password TIDAK PERNAH dikembalikan di response.
     */
    public function test_password_is_never_returned(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertArrayNotHasKey('password', $response->json('data.user'));
    }

    /**
     * Kolom role TIDAK boleh di-mass-assignment dari client (anti privilege escalation).
     */
    public function test_client_cannot_register_as_admin(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Penyerang',
            'email' => 'hacker@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'verifier_admin', // coba memaksa jadi admin
        ])->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'hacker@example.com',
            'role' => User::ROLE_CITIZEN, // tetap citizen
        ]);
    }

    /**
     * Password harus disimpan sebagai hash bcrypt (NFR-02).
     */
    public function test_password_is_stored_as_hash(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(201);

        $stored = User::where('email', 'budi@example.com')->first()->password;

        $this->assertNotSame('password123', $stored);
        $this->assertTrue(password_verify('password123', $stored));
    }

    /**
     * Login dengan kredensial benar -> dapat token.
     */
    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'warga@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'warga@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertNotEmpty($response->json('data.token'));
    }

    /**
     * Password salah -> 401 dengan pesan generik (tidak membocorkan email terdaftar).
     */
    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'warga@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'warga@example.com',
            'password' => 'password-keliru',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Email atau password salah.');
    }

    /**
     * Validasi gagal -> 422 dengan bentuk envelope + detail per field.
     */
    public function test_register_validation_error_shape(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'a',
            'email' => 'bukan-email',
            'password' => '123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'data', 'errors']);
    }

    /**
     * Endpoint terproteksi tanpa token -> 401 dengan bentuk envelope.
     */
    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    /**
     * Dengan token yang valid -> profil user berhasil dimuat.
     */
    public function test_me_returns_current_user(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', 'citizen');
    }

    /**
     * Logout menghapus token sehingga token lama tidak berlaku lagi.
     */
    public function test_logout_invalidates_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertStatus(200);

        // Baris token harus dihapus dari database (pembuktian server-side).
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Bersihkan cache guard, lalu pakai token yang sama.
        // Tanpa ini, user masih di-cache di memori dan request kedua
        // akan ikut berubah (200) walau tokennya sudah tidak berlaku.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
    }
}
