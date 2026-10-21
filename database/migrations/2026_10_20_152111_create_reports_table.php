<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel reports = laporan masyarakat (entitas utama proyek).
     *
     * Mengikuti ERD 4 tabel di SRS:
     *
     *  ticket_number  : nomor tiket otomatis "LPR-YYYY-XXXX" (FR-REP-03)
     *  user_id        : pelapor (warga)
     *  category_id    : kategori hasil DISPOSISI (nullable: bisa diisi AI nanti)
     *  ai_pred_cat_id : kategori TEBAKAN AI BERT   (FR-AI-02)
     *  ai_confidence  : confidence score 0.00-1.00  (FR-AI-02)
     *  report_type    : pengaduan | aspirasi
     *  status         : pending | verified | followed_up | rejected (BR-01)
     */
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();

            // Nomor tiket unik untuk dicari warga (FR-REP-03).
            $table->string('ticket_number')->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Nullable: warga boleh tidak memilih kategori (FR-REP-01) dan
            // AI pun bisa saja gagal/down (FR-AI-03 kondisi 3).
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            // Kategori tebakan AI - kolom evaluasi akurasi model (FR-ADM-03).
            $table->foreignId('ai_pred_cat_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->float('ai_confidence')->nullable();

            $table->enum('report_type', ['pengaduan', 'aspirasi']);

            $table->string('title');
            $table->text('description');

            // Wajib HANYA jika report_type = pengaduan (FR-REP-01),
            // jadi kolomnya dibuat nullable dan validasinya di level Form Request.
            $table->date('incident_date')->nullable();

            $table->string('image_url')->nullable();
            $table->string('location_text');

            // Toggle privasi & anonimitas (FR-REP-02).
            $table->boolean('is_anonymous')->default(false);
            $table->boolean('is_private')->default(false);

            $table->enum('status', [
                'pending',
                'verified',
                'followed_up',
                'rejected',
            ])->default('pending');

            $table->timestamps();

            // Index untuk filter dashboard (FR-PUB-01).
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
