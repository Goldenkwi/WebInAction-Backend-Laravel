<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel reports_logs = audit trail / timeline status laporan (NFR-04).
     *
     * Setiap perubahan status laporan menambah satu baris baru (append-only).
     * Kolom `note` punya fungsi ganda (SRS):
     *   - status verified   -> Memo Penugasan
     *   - status rejected   -> Alasan Penolakan
     *   - status followed_up -> Feedback / Catatan Tindak Lanjut
     *
     * Catatan: nama tabel di kode adalah "reports_logs" (mengikuti model
     * ReportsLog), sedangkan di ERD SRS ditulis "report_logs". Keduanya
     * hanya soal konvensi penamaan - kalau mau disamakan dengan ERD,
     * ubah nama tabel di migration ini dan di model ReportsLog.
     */
    public function up(): void
    {
        Schema::create('reports_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();

            // Nullable: log otomatis dari sistem AI tidak punya user.
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('status', [
                'pending',
                'verified',
                'followed_up',
                'rejected',
            ]);

            $table->text('note')->nullable();

            // Foto bukti penyelesaian (FR-ADM-04, opsional).
            $table->string('proof_img')->nullable();

            $table->timestamps();

            $table->index(['report_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports_logs');
    }
};
