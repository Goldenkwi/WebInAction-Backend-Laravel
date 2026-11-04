<?php

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\Categories;
use App\Models\Reports;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tes untuk master data instansi (AgencyController).
 *
 * Cara menjalankan:
 *   php artisan test --filter=AgenciesTest
 */
class AgenciesTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_VERIFIER]);
    }

    public function test_public_list_only_returns_active_agencies(): void
    {
        Agency::factory()->create(['name' => 'Dinas Aktif']);
        Agency::factory()->create(['name' => 'Dinas Nonaktif', 'is_active' => false]);

        $this->getJson('/api/agencies')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Dinas Aktif');
    }

    public function test_admin_can_include_inactive_agencies_in_list(): void
    {
        Agency::factory()->create();
        Agency::factory()->inactive()->create();

        $this->getJson('/api/agencies?active_only=0')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_agency_detail_contains_categories_and_report_counts(): void
    {
        $agency = Agency::factory()->create();
        $category = Categories::factory()->create(['default_agency_id' => $agency->id]);

        Reports::factory()->create([
            'category_id' => $category->id,
            'assigned_agency_id' => $agency->id,
            'status' => Reports::STATUS_FOLLOWED_UP,
        ]);

        $this->getJson('/api/agencies/'.$agency->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $agency->id)
            ->assertJsonPath('data.categories.0.id', $category->id)
            ->assertJsonPath('data.total_reports', 1)
            ->assertJsonPath('data.resolved_reports_count', 1);
    }

    public function test_guest_and_citizen_cannot_create_agency(): void
    {
        $payload = ['name' => 'Dinas Baru'];

        $this->postJson('/api/admin/agencies', $payload)->assertStatus(401);

        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        $this->actingAs($citizen)
            ->postJson('/api/admin/agencies', $payload)
            ->assertStatus(403);
    }

    public function test_admin_can_create_agency_with_logo(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(
            '/api/admin/agencies',
            [
                'name' => 'Dinas Pekerjaan Umum dan Penataan Ruang',
                'acronym' => 'DPUPR',
                'contact_email' => 'pengaduan@dpupr.go.id',
                'contact_phone' => '0811-2233-4455',
                'address' => 'Jl. Merdeka No. 10',
                'sla_days' => 5,
                'logo' => UploadedFile::fake()->create('dpupr.png', 100, 'image/png'),
            ],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Dinas Pekerjaan Umum dan Penataan Ruang')
            ->assertJsonPath('data.acronym', 'DPUPR')
            ->assertJsonPath('data.sla_days', 5);

        $agency = Agency::where('acronym', 'DPUPR')->firstOrFail();

        $this->assertDatabaseHas('agencies', [
            'id' => $agency->id,
            'is_active' => true,
        ]);
        Storage::disk('public')->assertExists($agency->logo_url);
        $this->assertStringContainsString('/storage/'.$agency->logo_url, $response->json('data.logo_full_url'));
    }

    public function test_admin_can_update_agency_and_replace_logo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('agencies/logo-lama.png', 'dummy');

        $admin = $this->admin();
        $agency = Agency::factory()->create([
            'name' => 'Nama Lama',
            'logo_url' => 'agencies/logo-lama.png',
        ]);

        $response = $this->actingAs($admin)->post(
            '/api/admin/agencies/'.$agency->id,
            [
                'name' => 'Nama Baru',
                'remove_logo' => false,
                'logo' => UploadedFile::fake()->create('logo-baru.png', 100, 'image/png'),
            ],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Nama Baru');

        $fresh = $agency->fresh();

        Storage::disk('public')->assertMissing('agencies/logo-lama.png');
        Storage::disk('public')->assertExists($fresh->logo_url);
    }

    public function test_agency_used_by_category_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $agency = Agency::factory()->create();
        Categories::factory()->create(['default_agency_id' => $agency->id]);

        $this->actingAs($admin)
            ->deleteJson('/api/admin/agencies/'.$agency->id)
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('agencies', ['id' => $agency->id]);
    }

    public function test_unused_agency_can_be_deleted_with_its_logo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('agencies/logo.png', 'dummy');

        $admin = $this->admin();
        $agency = Agency::factory()->create(['logo_url' => 'agencies/logo.png']);

        $this->actingAs($admin)
            ->deleteJson('/api/admin/agencies/'.$agency->id)
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('agencies', ['id' => $agency->id]);
        Storage::disk('public')->assertMissing('agencies/logo.png');
    }

    public function test_agency_name_must_be_unique(): void
    {
        $admin = $this->admin();
        Agency::factory()->create(['name' => 'Dinas Kesehatan']);

        $this->actingAs($admin)
            ->postJson('/api/admin/agencies', ['name' => 'Dinas Kesehatan'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
