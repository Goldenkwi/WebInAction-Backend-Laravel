<?php

namespace Tests\Feature\Api;

use App\Models\Categories;
use App\Models\Reports;
use App\Models\User;
use Database\Seeders\CategoriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Test untuk modul laporan warga (ReportsController).
 *
 * Cara menjalankan:
 *   php artisan test --filter=ReportsTest
 *
 * CATATAN TENTANG MOCK AI:
 * Kita memock HTTP call ke AI Microservice dengan Http::fake(). Ini menguji
 * jalur integrasi yang SESUNGGUHNYA (bukan mocking class AiBertService),
 * supaya mapping label_id -> kategori database ikut teruji.
 */
class ReportsTest extends TestCase
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

    /**
     * Respons palsu dari AI Microservice.
     */
    protected function fakeAiResponse(float $confidence = 0.91, string $label = 'Infrastruktur', int $labelId = 0, string $priority = 'tinggi'): void
    {
        Http::fake([
            '*/analyze' => Http::response([
                'category' => [
                    'label_id' => $labelId,
                    'label' => $label,
                    'confidence' => $confidence,
                ],
                'urgency' => [
                    'label_id' => 2,
                    'label' => $priority,
                    'confidence' => 0.78,
                ],
                'processing_ms' => 42,
            ], 200),
        ]);
    }

    /**
     * Payload form laporan yang valid.
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Lampu jalan mati dan kabel menjuntai di dekat sekolah',
            'report_type' => 'pengaduan',
            'description' => 'Sudah tiga malam lampu PJU padam total dan ada kabel turun yang membahayakan anak sekolah.',
            'location_text' => 'Jl. Telekomunikasi No. 12',
            'incident_date' => '2026-10-16',
        ], $overrides);
    }

    // ==================================================================
    // POST /api/reports
    // ==================================================================

    public function test_citizen_can_submit_report(): void
    {
        $this->fakeAiResponse();
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        $response = $this->actingAs($citizen)
            ->postJson('/api/reports', $this->payload());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure([
                'success', 'message',
                'data' => ['id', 'ticket_number', 'title', 'status', 'ai_analysis'],
            ]);

        $this->assertDatabaseHas('reports', [
            'user_id' => $citizen->id,
            'status' => Reports::STATUS_PENDING,
        ]);
    }

    public function test_ticket_number_is_generated_automatically(): void
    {
        $this->fakeAiResponse();
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        $response = $this->actingAs($citizen)->postJson('/api/reports', $this->payload());

        $ticket = $response->json('data.ticket_number');

        $this->assertMatchesRegularExpression('/^LPR-\d{4}-\d{4}$/', $ticket);
    }

    public function test_guest_cannot_submit_report(): void
    {
        $this->fakeAiResponse();

        $this->postJson('/api/reports', $this->payload())->assertStatus(401);
    }

    public function test_admin_cannot_submit_report(): void
    {
        $this->fakeAiResponse();
        $admin = User::factory()->create(['role' => User::ROLE_VERIFIER]);

        $this->actingAs($admin)
            ->postJson('/api/reports', $this->payload())
            ->assertStatus(403);
    }

    public function test_incident_date_is_required_for_pengaduan(): void
    {
        $this->fakeAiResponse();
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        $this->actingAs($citizen)
            ->postJson('/api/reports', $this->payload(['incident_date' => null]))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'data', 'errors']);
    }

    public function test_incident_date_is_optional_for_aspirasi(): void
    {
        $this->fakeAiResponse();
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        $this->actingAs($citizen)
            ->postJson('/api/reports', $this->payload([
                'report_type' => 'aspirasi',
                'incident_date' => null,
            ]))
            ->assertStatus(201);
    }

    public function test_title_must_be_at_least_10_characters(): void
    {
        $this->fakeAiResponse();
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        $this->actingAs($citizen)
            ->postJson('/api/reports', $this->payload(['title' => 'pendek']))
            ->assertStatus(422);
    }

    public function test_image_is_uploaded_and_stored(): void
    {
        Storage::fake('public');
        $this->fakeAiResponse();
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        $response = $this->actingAs($citizen)->postJson('/api/reports', $this->payload([
            'image' => UploadedFile::fake()->create('lampu.jpg', 100, 'image/jpeg'),
        ]));

        $response->assertStatus(201);

        $report = Reports::first();
        $this->assertNotNull($report->image_url);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $report->image_url));
    }

    public function test_image_larger_than_2mb_is_rejected(): void
    {
        Storage::fake('public');
        $this->fakeAiResponse();
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        $this->actingAs($citizen)->postJson('/api/reports', $this->payload([
            'image' => UploadedFile::fake()->create('besar.jpg', 3000, 'image/jpeg'),
        ]))->assertStatus(422);
    }

    public function test_image_with_invalid_format_is_rejected(): void
    {
        Storage::fake('public');
        $this->fakeAiResponse();
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        $this->actingAs($citizen)->postJson('/api/reports', $this->payload([
            'image' => UploadedFile::fake()->create('dokumen.pdf', 100, 'application/pdf'),
        ]))->assertStatus(422);
    }

    // ==================================================================
    // FR-AI-02 & FR-AI-03: perilaku AI
    // ==================================================================

    public function test_ai_prediction_is_stored_in_report(): void
    {
        $this->fakeAiResponse(confidence: 0.93, label: 'Infrastruktur', labelId: 0);
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);
        $category = Categories::where('ai_label_id', 0)->first();

        $this->actingAs($citizen)->postJson('/api/reports', $this->payload())->assertStatus(201);

        $this->assertDatabaseHas('reports', [
            'ai_pred_cat_id' => $category->id,
            'ai_confidence' => 0.93,
            'ai_priority' => 'tinggi',
            'priority' => 'tinggi',
        ]);
    }

    public function test_high_confidence_auto_fills_category(): void
    {
        $this->fakeAiResponse(confidence: 0.93);
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);
        $category = Categories::where('ai_label_id', 0)->first();

        $this->actingAs($citizen)->postJson('/api/reports', $this->payload())->assertStatus(201);

        $report = Reports::first();
        $this->assertSame($category->id, $report->category_id);
    }

    /**
     * Kalau warga memilih kategori sendiri, pilihan itu yang dipakai -
     * tapi tebakan AI tetap disimpan terpisah (FR-ADM-03, data evaluasi).
     */
    public function test_citizen_selected_category_wins_over_ai(): void
    {
        $this->fakeAiResponse(confidence: 0.93, label: 'Infrastruktur', labelId: 0);
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        $chosen = Categories::where('ai_label_id', 9)->first(); // Lingkungan
        $aiCategory = Categories::where('ai_label_id', 0)->first(); // Infrastruktur

        $this->actingAs($citizen)->postJson('/api/reports', $this->payload([
            'category_id' => $chosen->id,
        ]))->assertStatus(201);

        $report = Reports::first();
        $this->assertSame($chosen->id, $report->category_id);
        $this->assertSame($aiCategory->id, $report->ai_pred_cat_id);
        $this->assertTrue($report->isAiCorrected());
    }

    public function test_low_confidence_leaves_category_empty(): void
    {
        $this->fakeAiResponse(confidence: 0.42);
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        $this->actingAs($citizen)->postJson('/api/reports', $this->payload())->assertStatus(201);

        $report = Reports::first();
        $this->assertNull($report->category_id);
        $this->assertNotNull($report->ai_pred_cat_id);
    }

    public function test_report_is_still_saved_when_ai_service_is_down(): void
    {
        // FR-AI-03 kondisi 3 + NFR-05: AI down tidak boleh menggagalkan form.
        Http::fake([
            '*/analyze' => Http::response('', 500),
        ]);

        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        $response = $this->actingAs($citizen)->postJson('/api/reports', $this->payload());

        $response->assertStatus(201);

        $report = Reports::first();
        $this->assertSame(Reports::STATUS_PENDING, $report->status);
        $this->assertNull($report->ai_confidence);
        $this->assertNull($report->category_id);

        // Audit trail harus mencatat bahwa AI tidak tersedia.
        $this->assertStringContainsString('tidak tersedia', $report->logs()->first()->note);
    }

    public function test_report_is_still_saved_when_ai_connection_fails(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        $this->actingAs($citizen)->postJson('/api/reports', $this->payload())->assertStatus(201);

        $this->assertSame(Reports::STATUS_PENDING, Reports::first()->status);
    }

    public function test_initial_log_is_written_for_every_report(): void
    {
        $this->fakeAiResponse();
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        $this->actingAs($citizen)->postJson('/api/reports', $this->payload())->assertStatus(201);

        $report = Reports::first();
        $this->assertCount(1, $report->logs);
        $this->assertSame(Reports::STATUS_PENDING, $report->logs->first()->status);
        // Log sistem tidak punya changed_by.
        $this->assertNull($report->logs->first()->changed_by);
    }

    // ==================================================================
    // GET /api/reports/my
    // ==================================================================

    public function test_my_endpoint_only_returns_own_reports(): void
    {
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);
        $other = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        Reports::factory()->create(['user_id' => $citizen->id]);
        Reports::factory()->create(['user_id' => $other->id]);

        $response = $this->actingAs($citizen)->getJson('/api/reports/my');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($citizen->id, $response->json('data.0.user_id') ?? $citizen->id);
    }

    public function test_my_endpoint_requires_login(): void
    {
        $this->getJson('/api/reports/my')->assertStatus(401);
    }

    public function test_my_endpoint_includes_all_statuses(): void
    {
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);

        Reports::factory()->create(['user_id' => $citizen->id, 'status' => Reports::STATUS_PENDING]);
        Reports::factory()->create(['user_id' => $citizen->id, 'status' => Reports::STATUS_VERIFIED]);
        Reports::factory()->create(['user_id' => $citizen->id, 'status' => Reports::STATUS_FOLLOWED_UP]);
        Reports::factory()->create(['user_id' => $citizen->id, 'status' => Reports::STATUS_REJECTED]);

        $response = $this->actingAs($citizen)->getJson('/api/reports/my');

        $this->assertCount(4, $response->json('data'));
    }

    // ==================================================================
    // GET /api/reports/public  (BR-03)
    // ==================================================================

    public function test_public_endpoint_only_shows_published_reports(): void
    {
        // published
        Reports::factory()->create(['status' => Reports::STATUS_VERIFIED, 'is_private' => false]);
        Reports::factory()->create(['status' => Reports::STATUS_FOLLOWED_UP, 'is_private' => false]);
        // tidak boleh muncul
        Reports::factory()->create(['status' => Reports::STATUS_PENDING, 'is_private' => false]);
        Reports::factory()->create(['status' => Reports::STATUS_REJECTED, 'is_private' => false]);
        Reports::factory()->create(['status' => Reports::STATUS_VERIFIED, 'is_private' => true]);

        $response = $this->getJson('/api/reports/public');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_public_endpoint_is_publicly_accessible(): void
    {
        Reports::factory()->create(['status' => Reports::STATUS_VERIFIED, 'is_private' => false]);

        $this->getJson('/api/reports/public')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_public_endpoint_can_be_filtered(): void
    {
        $category = Categories::where('ai_label_id', 0)->first();

        Reports::factory()->create([
            'status' => Reports::STATUS_VERIFIED,
            'is_private' => false,
            'category_id' => $category->id,
            'title' => 'Lampu jalan mati di sekolah',
        ]);
        Reports::factory()->create([
            'status' => Reports::STATUS_VERIFIED,
            'is_private' => false,
            'title' => 'Sampah menumpuk di pasar',
        ]);

        $response = $this->getJson('/api/reports/public?search=lampu');

        $this->assertCount(1, $response->json('data'));
    }

    // ==================================================================
    // GET /api/reports/{id}
    // ==================================================================

    public function test_owner_can_view_own_private_pending_report(): void
    {
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);
        $report = Reports::factory()->create([
            'user_id' => $citizen->id,
            'status' => Reports::STATUS_PENDING,
            'is_private' => true,
        ]);

        $this->actingAs($citizen)
            ->getJson('/api/reports/'.$report->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $report->id);
    }

    public function test_other_citizen_cannot_view_private_report(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_CITIZEN]);
        $other = User::factory()->create(['role' => User::ROLE_CITIZEN]);
        $report = Reports::factory()->create([
            'user_id' => $owner->id,
            'status' => Reports::STATUS_PENDING,
            'is_private' => true,
        ]);

        $this->actingAs($other)
            ->getJson('/api/reports/'.$report->id)
            ->assertStatus(403);
    }

    public function test_citizen_can_view_published_public_report(): void
    {
        $other = User::factory()->create(['role' => User::ROLE_CITIZEN]);
        $report = Reports::factory()->create([
            'status' => Reports::STATUS_VERIFIED,
            'is_private' => false,
        ]);

        $this->actingAs($other)
            ->getJson('/api/reports/'.$report->id)
            ->assertStatus(200);
    }

    public function test_admin_can_view_any_report(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_VERIFIER]);
        $report = Reports::factory()->create([
            'status' => Reports::STATUS_PENDING,
            'is_private' => true,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/reports/'.$report->id)
            ->assertStatus(200);
    }

    public function test_detail_contains_timeline_logs(): void
    {
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);
        $report = Reports::factory()->create(['user_id' => $citizen->id]);

        $response = $this->actingAs($citizen)->getJson('/api/reports/'.$report->id);

        $response->assertStatus(200)->assertJsonStructure([
            'data' => ['id', 'ticket_number', 'title', 'description', 'status', 'timeline_logs'],
        ]);
    }

    // ==================================================================
    // NFR-02: penyensoran nama pelapor
    // ==================================================================

    public function test_anonymous_report_hides_reporter_name(): void
    {
        $other = User::factory()->create(['role' => User::ROLE_CITIZEN]);
        $report = Reports::factory()->create([
            'status' => Reports::STATUS_VERIFIED,
            'is_private' => false,
            'is_anonymous' => true,
        ]);

        $response = $this->actingAs($other)->getJson('/api/reports/'.$report->id);

        $response->assertStatus(200)->assertJsonPath('data.reporter_name', 'Anonymous');
    }

    public function test_anonymous_report_shows_real_name_to_admin(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_VERIFIER]);
        $report = Reports::factory()->create([
            'status' => Reports::STATUS_VERIFIED,
            'is_anonymous' => true,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/reports/'.$report->id);

        $response->assertStatus(200);
        $this->assertNotSame('Anonymous', $response->json('data.reporter_name'));
    }

    public function test_anonymous_report_shows_real_name_to_owner(): void
    {
        $citizen = User::factory()->create(['role' => User::ROLE_CITIZEN]);
        $report = Reports::factory()->create([
            'user_id' => $citizen->id,
            'status' => Reports::STATUS_VERIFIED,
            'is_anonymous' => true,
        ]);

        $response = $this->actingAs($citizen)->getJson('/api/reports/'.$report->id);

        $this->assertNotSame('Anonymous', $response->json('data.reporter_name'));
    }
}
