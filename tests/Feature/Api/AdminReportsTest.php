<?php

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\Categories;
use App\Models\Reports;
use App\Models\ReportsLog;
use App\Models\User;
use Database\Seeders\CategoriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Test untuk modul admin laporan (Admin\ReportController).
 *
 * Yang diuji:
 *   - FR-ADM-01 verifikasi + memo penugasan
 *   - FR-ADM-02 penolakan + alasan
 *   - FR-ADM-04 tindak lanjut + feedback + bukti foto
 *   - FR-ADM-03 koreksi kategori tersimpan terpisah dari tebakan AI
 *   - BR-01 transisi status satu arah
 *   - BR-02 note/feedback wajib
 *   - Otorisasi role
 */
class AdminReportsTest extends TestCase
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

    protected function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_VERIFIER]);
    }

    protected function citizen(): User
    {
        return User::factory()->create(['role' => User::ROLE_CITIZEN]);
    }

    // ==================================================================
    // GET /api/admin/reports
    // ==================================================================

    public function test_admin_can_list_all_reports(): void
    {
        $admin = $this->admin();
        Reports::factory()->count(3)->create();

        $response = $this->actingAs($admin)->getJson('/api/admin/reports');

        $response->assertStatus(200)->assertJsonCount(3, 'data');
    }

    public function test_citizen_cannot_list_admin_reports(): void
    {
        $this->actingAs($this->citizen())
            ->getJson('/api/admin/reports')
            ->assertStatus(403);
    }

    public function test_admin_list_can_be_filtered_by_status(): void
    {
        $admin = $this->admin();
        Reports::factory()->create(['status' => Reports::STATUS_PENDING]);
        Reports::factory()->create(['status' => Reports::STATUS_VERIFIED]);

        $response = $this->actingAs($admin)->getJson('/api/admin/reports?status=pending');

        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_list_can_be_filtered_by_priority(): void
    {
        $admin = $this->admin();
        Reports::factory()->create(['priority' => Reports::PRIORITY_DARURAT]);
        Reports::factory()->create(['priority' => Reports::PRIORITY_RENDAH]);

        $response = $this->actingAs($admin)->getJson('/api/admin/reports?priority=darurat');

        $this->assertCount(1, $response->json('data'));
    }

    // ==================================================================
    // PATCH /api/admin/reports/{id}/verify  (FR-ADM-01)
    // ==================================================================

    public function test_admin_can_verify_report_with_memo(): void
    {
        $admin = $this->admin();
        $report = Reports::factory()->create(['status' => Reports::STATUS_PENDING]);
        $category = Categories::where('ai_label_id', 0)->first();

        $response = $this->actingAs($admin)->patchJson(
            '/api/admin/reports/'.$report->id.'/verify',
            [
                'category_id' => $category->id,
                'note' => 'Mohon tim teknis segera menangani lampu penerangan jalan.',
            ]
        );

        $response->assertStatus(200)->assertJsonPath('data.status', 'verified');

        $this->assertDatabaseHas('reports', [
            'id' => $report->id,
            'status' => Reports::STATUS_VERIFIED,
            'category_id' => $category->id,
        ]);

        $this->assertDatabaseHas('reports_logs', [
            'report_id' => $report->id,
            'status' => Reports::STATUS_VERIFIED,
            'changed_by' => $admin->id,
        ]);
    }

    public function test_verify_requires_note(): void
    {
        $admin = $this->admin();
        $report = Reports::factory()->create(['status' => Reports::STATUS_PENDING]);
        $category = Categories::where('ai_label_id', 0)->first();

        // BR-02: memo wajib.
        $this->actingAs($admin)
            ->patchJson('/api/admin/reports/'.$report->id.'/verify', [
                'category_id' => $category->id,
                'note' => '',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(Reports::STATUS_PENDING, $report->fresh()->status);
    }

    public function test_verify_requires_valid_category(): void
    {
        $admin = $this->admin();
        $report = Reports::factory()->create(['status' => Reports::STATUS_PENDING]);

        $this->actingAs($admin)
            ->patchJson('/api/admin/reports/'.$report->id.'/verify', [
                'category_id' => 99999,
                'note' => 'Memo penugasan yang cukup panjang.',
            ])
            ->assertStatus(422);
    }

    public function test_citizen_cannot_verify_report(): void
    {
        $report = Reports::factory()->create(['status' => Reports::STATUS_PENDING]);
        $category = Categories::where('ai_label_id', 0)->first();

        $this->actingAs($this->citizen())
            ->patchJson('/api/admin/reports/'.$report->id.'/verify', [
                'category_id' => $category->id,
                'note' => 'Mohon tim teknis segera menangani.',
            ])
            ->assertStatus(403);
    }

    /**
     * FR-ADM-03: koreksi admin terhadap tebakan AI harus TERPISAH.
     */
    public function test_admin_correction_is_stored_separately_from_ai_prediction(): void
    {
        $admin = $this->admin();
        $report = Reports::factory()->create(['status' => Reports::STATUS_PENDING]);

        $aiCategory = Categories::where('ai_label_id', 0)->first();  // Infrastruktur
        $finalCategory = Categories::where('ai_label_id', 9)->first();  // Lingkungan

        $report->update(['ai_pred_cat_id' => $aiCategory->id]);

        $this->actingAs($admin)->patchJson(
            '/api/admin/reports/'.$report->id.'/verify',
            [
                'category_id' => $finalCategory->id,
                'note' => 'Tebakan AI dikoreksi menjadi Lingkungan.',
            ]
        )->assertStatus(200);

        $fresh = $report->fresh();
        $this->assertSame($finalCategory->id, $fresh->category_id);
        $this->assertSame($aiCategory->id, $fresh->ai_pred_cat_id);
        $this->assertTrue($fresh->isAiCorrected());
    }

    // ==================================================================
    // PATCH /api/admin/reports/{id}/reject  (FR-ADM-02)
    // ==================================================================

    public function test_admin_can_reject_report_with_reason(): void
    {
        $admin = $this->admin();
        $report = Reports::factory()->create(['status' => Reports::STATUS_PENDING]);

        $this->actingAs($admin)->patchJson(
            '/api/admin/reports/'.$report->id.'/reject',
            ['note' => 'Konten tidak menjelaskan masalah spesifik dan tidak dapat diverifikasi.']
        )->assertStatus(200)->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('reports_logs', [
            'report_id' => $report->id,
            'status' => Reports::STATUS_REJECTED,
        ]);
    }

    public function test_reject_requires_reason(): void
    {
        $admin = $this->admin();
        $report = Reports::factory()->create(['status' => Reports::STATUS_PENDING]);

        // BR-02: alasan wajib.
        $this->actingAs($admin)
            ->patchJson('/api/admin/reports/'.$report->id.'/reject', ['note' => ''])
            ->assertStatus(422);
    }

    // ==================================================================
    // POST /api/admin/reports/{id}/followup  (FR-ADM-04)
    // ==================================================================

    public function test_admin_can_follow_up_verified_report(): void
    {
        $admin = $this->admin();
        $report = Reports::factory()->create(['status' => Reports::STATUS_VERIFIED]);

        $this->actingAs($admin)->postJson(
            '/api/admin/reports/'.$report->id.'/followup',
            ['note' => 'Lampu PJU sudah diganti dan kabel telah diamankan.']
        )->assertStatus(200)->assertJsonPath('data.status', 'followed_up');

        $this->assertDatabaseHas('reports', [
            'id' => $report->id,
            'status' => Reports::STATUS_FOLLOWED_UP,
        ]);
    }

    public function test_follow_up_requires_feedback(): void
    {
        $admin = $this->admin();
        $report = Reports::factory()->create(['status' => Reports::STATUS_VERIFIED]);

        // BR-02: feedback wajib.
        $this->actingAs($admin)
            ->postJson('/api/admin/reports/'.$report->id.'/followup', ['note' => ''])
            ->assertStatus(422);
    }

    public function test_follow_up_can_upload_proof_image(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        $report = Reports::factory()->create(['status' => Reports::STATUS_VERIFIED]);

        $this->actingAs($admin)->postJson(
            '/api/admin/reports/'.$report->id.'/followup',
            [
                'note' => 'Pekerjaan telah selesai, foto bukti dilampirkan.',
                'proof_img' => UploadedFile::fake()->create('bukti.jpg', 100, 'image/jpeg'),
            ]
        )->assertStatus(200);

        $log = ReportsLog::where('report_id', $report->id)
            ->where('status', Reports::STATUS_FOLLOWED_UP)
            ->first();

        $this->assertNotNull($log);
        $this->assertNotNull($log->proof_img);
    }

    public function test_proof_image_larger_than_2mb_is_rejected(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        $report = Reports::factory()->create(['status' => Reports::STATUS_VERIFIED]);

        $this->actingAs($admin)->postJson(
            '/api/admin/reports/'.$report->id.'/followup',
            [
                'note' => 'Pekerjaan selesai dengan foto bukti.',
                'proof_img' => UploadedFile::fake()->create('bukti.jpg', 100, 'image/jpeg')->size(3000),
            ]
        )->assertStatus(422);
    }

    // ==================================================================
    // BR-01: transisi status satu arah
    // ==================================================================

    public function test_cannot_follow_up_a_pending_report(): void
    {
        $admin = $this->admin();
        $report = Reports::factory()->create(['status' => Reports::STATUS_PENDING]);

        // BR-01: tidak boleh loncat dari pending langsung ke tindak lanjut.
        $this->actingAs($admin)->postJson(
            '/api/admin/reports/'.$report->id.'/followup',
            ['note' => 'Langsung tindak lanjut tanpa tahap verifikasi terlebih dahulu.']
        )->assertStatus(409)->assertJsonPath('success', false);

        $this->assertSame(Reports::STATUS_PENDING, $report->fresh()->status);
    }

    public function test_cannot_verify_a_rejected_report(): void
    {
        $admin = $this->admin();
        $report = Reports::factory()->create(['status' => Reports::STATUS_REJECTED]);
        $category = Categories::where('ai_label_id', 0)->first();

        $this->actingAs($admin)->patchJson(
            '/api/admin/reports/'.$report->id.'/verify',
            [
                'category_id' => $category->id,
                'note' => 'Mohon verifikasi ulang laporan ini.',
            ]
        )->assertStatus(409)->assertJsonPath('success', false);
    }

    public function test_cannot_reject_a_followed_up_report(): void
    {
        $admin = $this->admin();
        $report = Reports::factory()->create(['status' => Reports::STATUS_FOLLOWED_UP]);

        $this->actingAs($admin)->patchJson(
            '/api/admin/reports/'.$report->id.'/reject',
            ['note' => 'Mohon laporan ini ditutup dengan alasan tertentu.']
        )->assertStatus(409);
    }

    public function test_cannot_follow_up_a_followed_up_report(): void
    {
        $admin = $this->admin();
        $report = Reports::factory()->create(['status' => Reports::STATUS_FOLLOWED_UP]);

        $this->actingAs($admin)->postJson(
            '/api/admin/reports/'.$report->id.'/followup',
            ['note' => 'Tindak lanjut kedua kali untuk laporan yang sama.']
        )->assertStatus(409);
    }

    public function test_transition_error_uses_standard_envelope(): void
    {
        $admin = $this->admin();
        $report = Reports::factory()->create(['status' => Reports::STATUS_REJECTED]);
        $category = Categories::where('ai_label_id', 0)->first();

        $this->actingAs($admin)->patchJson(
            '/api/admin/reports/'.$report->id.'/verify',
            [
                'category_id' => $category->id,
                'note' => 'Mohon verifikasi ulang laporan yang sudah ditolak.',
            ]
        )->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'data']);
    }

    // ==================================================================
    // DISPOSISI INSTANSI
    // ==================================================================

    public function test_verify_uses_category_default_agency_when_agency_not_chosen(): void
    {
        $admin = $this->admin();
        $agency = Agency::factory()->create();
        $category = Categories::where('ai_label_id', 0)->first();
        $category->update(['default_agency_id' => $agency->id]);

        $report = Reports::factory()->create(['status' => Reports::STATUS_PENDING]);

        $this->actingAs($admin)->patchJson(
            '/api/admin/reports/'.$report->id.'/verify',
            [
                'category_id' => $category->id,
                'note' => 'Diteruskan ke instansi default kategori ini.',
            ]
        )->assertStatus(200)
            ->assertJsonPath('data.agency.id', $agency->id);

        $this->assertDatabaseHas('reports', [
            'id' => $report->id,
            'assigned_agency_id' => $agency->id,
        ]);
    }

    public function test_verifier_can_override_default_agency_during_verify(): void
    {
        $admin = $this->admin();
        $defaultAgency = Agency::factory()->create();
        $chosenAgency = Agency::factory()->create();
        $category = Categories::where('ai_label_id', 0)->first();
        $category->update(['default_agency_id' => $defaultAgency->id]);

        $report = Reports::factory()->create(['status' => Reports::STATUS_PENDING]);

        $this->actingAs($admin)->patchJson(
            '/api/admin/reports/'.$report->id.'/verify',
            [
                'category_id' => $category->id,
                'agency_id' => $chosenAgency->id,
                'note' => 'Instansi dipilih manual karena lintas tupoksi.',
            ]
        )->assertStatus(200)
            ->assertJsonPath('data.agency.id', $chosenAgency->id);

        $this->assertDatabaseHas('reports', [
            'id' => $report->id,
            'assigned_agency_id' => $chosenAgency->id,
        ]);
    }

    public function test_agency_admin_only_lists_reports_assigned_to_own_agency(): void
    {
        $ownAgency = Agency::factory()->create();
        $otherAgency = Agency::factory()->create();

        $agencyAdmin = User::factory()->create([
            'role' => User::ROLE_AGENCY,
            'agency_id' => $ownAgency->id,
        ]);

        Reports::factory()->create(['assigned_agency_id' => $ownAgency->id]);
        Reports::factory()->create(['assigned_agency_id' => $otherAgency->id]);

        $this->actingAs($agencyAdmin)
            ->getJson('/api/admin/reports')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.agency.id', $ownAgency->id);
    }

    public function test_agency_admin_cannot_follow_up_other_agency_report(): void
    {
        $ownAgency = Agency::factory()->create();
        $otherAgency = Agency::factory()->create();

        $agencyAdmin = User::factory()->create([
            'role' => User::ROLE_AGENCY,
            'agency_id' => $ownAgency->id,
        ]);

        $otherReport = Reports::factory()->create([
            'status' => Reports::STATUS_VERIFIED,
            'assigned_agency_id' => $otherAgency->id,
        ]);

        $this->actingAs($agencyAdmin)->postJson(
            '/api/admin/reports/'.$otherReport->id.'/followup',
            ['note' => 'Mencoba menindaklanjuti laporan instansi lain.']
        )->assertStatus(403);
    }

    public function test_agency_admin_can_follow_up_own_agency_report(): void
    {
        $agency = Agency::factory()->create();

        $agencyAdmin = User::factory()->create([
            'role' => User::ROLE_AGENCY,
            'agency_id' => $agency->id,
        ]);

        $report = Reports::factory()->create([
            'status' => Reports::STATUS_VERIFIED,
            'assigned_agency_id' => $agency->id,
        ]);

        $this->actingAs($agencyAdmin)->postJson(
            '/api/admin/reports/'.$report->id.'/followup',
            ['note' => 'Laporan sudah ditangani oleh petugas instansi kami.']
        )->assertStatus(200)
            ->assertJsonPath('data.status', 'followed_up');
    }
}
