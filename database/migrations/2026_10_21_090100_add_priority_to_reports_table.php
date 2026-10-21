<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan 3 kolom prioritas/urgensi ke tabel reports.
     *
     * ---------------------------------------------------------------------------
     * KONSEP: KENAPA 3 KOLOM, BUKAN 1?
     * ---------------------------------------------------------------------------
     * Kita punya DUA sumber informasi prioritas:
     *   1. AI (model urgency/urgensi) -> output mentah + confidence-nya
     *   2. Admin -> boleh mengoreksi
     *
     * Kalau hanya 1 kolom `priority`, kita kehilangan jejak "siapa yang bilang
     * apa" - dan kolom `ai_pred_cat_id` vs `category_id` yang jadi bahan
     * evaluasi akurasi model (SRS FR-ADM-03) jadi tidak ada padanannya.
     *
     * Maka:
     *
     *   ai_priority            enum, nullable -> output mentah model (tidak pernah
     *                                        diubah admin, ini "data evaluator")
     *   ai_priority_confidence float, nullable -> keyakinan model (0.00-1.00)
     *   priority               enum, nullable -> prioritas EFEKTIF yang dipakai
     *                                        aplikasi. Awalnya = ai_priority,
     *                                        tapi admin boleh mengoreksi.
     *
     * Kalau AI-nya down / timeout, `ai_priority` = NULL (bukan default palsu
     * seperti "sedang"). NULL berarti "tidak diketahui" - jujur dan tidak
     * menyesatkan. Ini pendekatan yang sama seperti `ai_confidence` di
     * tabel reports.
     *
     * ---------------------------------------------------------------------------
     * KENAPA ENUM DAN BUKAN TINYINT?
     * ---------------------------------------------------------------------------
     * Karena nilainya sudah tetap (4 level) dan muncul di banyak tempat
     * (filter, badge UI, statistik). Enum = aman dari typo ("Darurrat"),
     * dan MySQL/SQLite bisa memvalidasi nilainya di level database.
     *
     * Catatan: 4 label model urgency = 0=Darurat, 1=Tinggi, 2=Sedang, 3=Rendah.
     */
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            // Output mentah dari model AI.
            $table->enum('ai_priority', ['rendah', 'sedang', 'tinggi', 'darurat'])
                ->nullable()
                ->after('ai_confidence');

            // Keyakinan model terhadap prioritas tersebut (0.00 - 1.00).
            $table->float('ai_priority_confidence')->nullable()->after('ai_priority');

            // Prioritas efektif yang dipakai aplikasi (bisa dikoreksi admin).
            $table->enum('priority', ['rendah', 'sedang', 'tinggi', 'darurat'])
                ->nullable()
                ->after('ai_priority_confidence');

            // Index untuk sorting/filter tabel admin berdasarkan prioritas.
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex(['priority']);
            $table->dropColumn(['ai_priority', 'ai_priority_confidence', 'priority']);
        });
    }
};
