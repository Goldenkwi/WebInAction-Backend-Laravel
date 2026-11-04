<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menghubungkan tabel agencies ke tiga tabel yang sudah ada.
     *
     *  users.agency_id            -> instansi tempat petugas (agency_admin) bertugas
     *  categories.default_agency_id -> instansi default untuk kategori ini
     *  reports.assigned_agency_id -> instansi FINAL yang ditugaskan admin
     *
     * Semua kolom nullable supaya data lama tetap valid:
     *  - warga & verifikator tidak punya instansi
     *  - kategori lama boleh belum punya instansi default
     *  - laporan pending/rejected belum tentu sudah ditugaskan
     *
     * nullOnDelete dipakai agar menghapus instansi tidak menghapus user,
     * kategori, atau laporan historis. Controller tetap mencegah penghapusan
     * instansi yang masih dipakai agar histori tidak kehilangan konteks.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('agency_id')
                ->nullable()
                ->after('role')
                ->constrained('agencies')
                ->nullOnDelete();
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('default_agency_id')
                ->nullable()
                ->after('ai_label_id')
                ->constrained('agencies')
                ->nullOnDelete();
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('assigned_agency_id')
                ->nullable()
                ->after('ai_pred_cat_id')
                ->constrained('agencies')
                ->nullOnDelete();

            // Filter dashboard instansi: Report::where('assigned_agency_id', ...).
            $table->index('assigned_agency_id');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex(['assigned_agency_id']);
            $table->dropConstrainedForeignId('assigned_agency_id');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_agency_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_id');
        });
    }
};
