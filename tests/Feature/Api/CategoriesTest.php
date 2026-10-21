<?php

namespace Tests\Feature\Api;

use App\Models\Categories;
use App\Models\Reports;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tes untuk modul Categories.
 *
 * Cara menjalankan:
 *   php artisan test --filter=CategoriesTest
 */
class CategoriesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Daftar kategori bisa diakses publik (tanpa login) - sesuai SRS.
     */
    public function test_categories_are_publicly_listed(): void
    {
        Categories::factory()->count(3)->create();

        $this->getJson('/api/categories')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [['id', 'name', 'icon', 'created_at']],
            ]);
    }

    /**
     * Filter ?search= bekerja (case-insensitive).
     */
    public function test_categories_can_be_searched(): void
    {
        Categories::factory()->create(['name' => 'Infrastruktur']);
        Categories::factory()->create(['name' => 'Kesehatan']);

        $this->getJson('/api/categories?search=infra')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Infrastruktur');
    }

    /**
     * ?with_counts=1 menyertakan jumlah laporan per kategori.
     */
    public function test_categories_can_include_reports_count(): void
    {
        $category = Categories::factory()->create();

        $this->getJson('/api/categories?with_counts=1')
            ->assertStatus(200)
            ->assertJsonPath('data.0.reports_count', 0);

        $this->assertNotNull($category);
    }

    /**
     * Guest TIDAK boleh membuat kategori -> 403 (karena middleware can:).
     */
    public function test_guest_cannot_create_category(): void
    {
        $this->postJson('/api/categories', ['name' => 'Infrastruktur'])
            ->assertStatus(401);
    }

    /**
     * Warga biasa (citizen) TIDAK boleh membuat kategori -> 403.
     */
    public function test_citizen_cannot_create_category(): void
    {
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        $this->actingAs($citizen)
            ->postJson('/api/categories', ['name' => 'Infrastruktur'])
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    /**
     * Admin verifier boleh membuat kategori.
     */
    public function test_admin_can_create_category(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_VERIFIER]);

        $response = $this->actingAs($admin)
            ->postJson('/api/categories', ['name' => 'Infrastruktur', 'icon' => '🛣️']);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Infrastruktur');

        $this->assertDatabaseHas('categories', ['name' => 'Infrastruktur']);
    }

    /**
     * Nama kategori harus unik.
     */
    public function test_category_name_must_be_unique(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_VERIFIER]);
        Categories::factory()->create(['name' => 'Infrastruktur']);

        $this->actingAs($admin)
            ->postJson('/api/categories', ['name' => 'Infrastruktur'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * Kategori yang dipakai laporan tidak boleh dihapus (409 Conflict).
     */
    public function test_category_in_use_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_VERIFIER]);
        $category = Categories::factory()->create();

        // Buat satu laporan yang memakai kategori ini.
        Reports::create([
            'ticket_number' => 'LPR-2026-0001',
            'user_id' => $admin->id,
            'category_id' => $category->id,
            'report_type' => 'pengaduan',
            'title' => 'Lampu jalan mati',
            'description' => 'Lampu PJU padam selama tiga malam.',
            'location_text' => 'Jl. Telekomunikasi No. 12',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/categories/'.$category->id)
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    /**
     * Kategori yang belum dipakai laporan boleh dihapus.
     */
    public function test_unused_category_can_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_VERIFIER]);
        $category = Categories::factory()->create();

        $this->actingAs($admin)
            ->deleteJson('/api/categories/'.$category->id)
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    /**
     * Update kategori: field yang tidak dikirim tidak berubah (PATCH semantics).
     */
    public function test_admin_can_update_category(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_VERIFIER]);
        $category = Categories::factory()->create(['name' => 'Infrastruktur', 'icon' => '🛣️']);

        $this->actingAs($admin)
            ->putJson('/api/categories/'.$category->id, ['name' => 'Infrastruktur & Utilitas'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Infrastruktur & Utilitas');

        // icon tidak dikirim -> harus tetap sama
        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'icon' => '🛣️',
        ]);
    }

    /**
     * Detail kategori yang tidak ada -> 404 dengan envelope.
     */
    public function test_unknown_category_returns_404(): void
    {
        $this->getJson('/api/categories/9999')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }
}
