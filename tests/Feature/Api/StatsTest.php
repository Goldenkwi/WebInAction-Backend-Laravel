<?php

namespace Tests\Feature\Api;

use App\Models\Categories;
use App\Models\Reports;
use App\Models\ReportsLog;
use App\Models\User;
use Database\Seeders\CategoriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test untuk endpoint statistik dashboard (FR-STAT-01).
 */
class StatsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Kategori harus ada di database test supaya test bisa merujuk
     * kategori lewat ai_label_id (label model).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategoriesSeeder::class);
    }

    public function test_stats_requires_login(): void
    {
        $this->getJson('/api/stats/summary')->assertStatus(401);
    }

    public function test_stats_returns_the_four_main_metrics(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        Reports::factory()->count(2)->create(['status' => Reports::STATUS_PENDING]);
        Reports::factory()->create(['status' => Reports::STATUS_VERIFIED]);
        Reports::factory()->create(['status' => Reports::STATUS_FOLLOWED_UP]);
        Reports::factory()->create(['status' => Reports::STATUS_REJECTED]);

        $response = $this->actingAs($user)->getJson('/api/stats/summary');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_reports', 5)
            ->assertJsonPath('data.pending_reports', 2)
            ->assertJsonPath('data.completed_reports', 1)
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'total_reports', 'pending_reports', 'completed_reports',
                    'average_resolution_days', 'by_status', 'by_category', 'by_priority',
                ],
            ]);
    }

    public function test_average_resolution_days_is_computed_from_logs(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CITIZEN]);
        $report = Reports::factory()->create([
            'status' => Reports::STATUS_FOLLOWED_UP,
            'created_at' => now()->subDays(4),
        ]);

        // Log tindak lanjut 2 hari setelah laporan dibuat -> 2 hari.
        ReportsLog::forceCreate([
            'report_id' => $report->id,
            'changed_by' => null,
            'status' => Reports::STATUS_FOLLOWED_UP,
            'note' => 'Selesai',
            'created_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($user)->getJson('/api/stats/summary');

        // json_encode(2.0) menghasilkan "2" (bukan "2.0"), jadi pakai
        // assertEquals yang longgar terhadap tipe.
        $this->assertEquals(2, $response->json('data.average_resolution_days'));
    }

    public function test_average_resolution_days_is_zero_when_nothing_completed(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CITIZEN]);
        Reports::factory()->create(['status' => Reports::STATUS_PENDING]);

        $this->actingAs($user)
            ->getJson('/api/stats/summary')
            ->assertJsonPath('data.average_resolution_days', 0);
    }

    public function test_by_category_breakdown_counts_reports(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CITIZEN]);
        $category = Categories::where('ai_label_id', 0)->first();

        Reports::factory()->count(3)->create([
            'status' => Reports::STATUS_VERIFIED,
            'category_id' => $category->id,
        ]);
        Reports::factory()->create([
            'status' => Reports::STATUS_PENDING,
            'category_id' => null,
        ]);

        $response = $this->actingAs($user)->getJson('/api/stats/summary');

        $breakdown = collect($response->json('data.by_category'))
            ->firstWhere('category_id', $category->id);

        $this->assertNotNull($breakdown);
        $this->assertSame(3, $breakdown['count']);
        $this->assertSame('Infrastruktur', $breakdown['name']);
    }

    public function test_by_priority_breakdown_contains_all_four_levels(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        Reports::factory()->create(['priority' => Reports::PRIORITY_DARURAT]);
        Reports::factory()->count(2)->create(['priority' => Reports::PRIORITY_RENDAH]);

        $response = $this->actingAs($user)->getJson('/api/stats/summary');

        $breakdown = collect($response->json('data.by_priority'))
            ->keyBy('priority');

        $this->assertSame(1, $breakdown['darurat']['count']);
        $this->assertSame(2, $breakdown['rendah']['count']);
        $this->assertSame(0, $breakdown['tinggi']['count']);
        $this->assertSame(0, $breakdown['sedang']['count']);
    }

    public function test_stats_are_visible_to_admin_as_well(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_VERIFIER]);

        $this->actingAs($admin)
            ->getJson('/api/stats/summary')
            ->assertStatus(200)
            ->assertJsonPath('data.total_reports', 0);
    }
}
