<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan kolom `ai_label_id` ke tabel categories.
     *
     * ---------------------------------------------------------------------------
     * KONSEP: PEMETAAN LABEL MODEL -> KATEGORI DI DATABASE
     * ---------------------------------------------------------------------------
     * Model AI (IndoBERT P2 fine-tuned) mengembalikan label dalam bentuk ANGKA:
     *
     *   id2label = {
     *     "0":  "Infrastruktur",
     *     "1":  "Kebersihan",
     *     "2":  "Keamanan",
     *     ...
     *     "11": "Lain-lain"
     *   }
     *
     * Angka itu (0-11) adalah "nomor urut label" di dalam model - BUKAN id
     * primary key di database. Kalau kita mencocokkan pakai nama string
     * ("Infrastruktur"), rawan masalah karena:
     *   - perbedaan huruf besar/kecil ("Lain-lain" vs "lain-lain")
     *   - spasi/underscore ("IT & Jaringan" vs "IT_Jaringan")
     *   - karakter khusus ("&")
     *
     * Dengan kolom `ai_label_id`, tiap baris kategori MENYATAKAN sendiri label
     * model mana yang Mewakainya. Jadi:
     *
     *   categories.id = 1 (Infrastruktur)  <-> ai_label_id = 0
     *   categories.id = 2 (Kebersihan)    <-> ai_label_id = 1
     *   ...
     *
     * Sekarang Laravel cukup cari kategori dengan `where('ai_label_id', 0)`.
     * Tidak ada string matching, tidak ada asumsi urutan id.
     *
     * Bonus: kolom ini jadi "dokumen kontrak" antar 2 service. Ada command
     * `php artisan ai:check-labels` yang membandingkannya dengan id2label
     * model sungguhan -> kalau ada yang tidak cocok, ketahuan sebelum demo.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->tinyInteger('ai_label_id')->nullable()->unique()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['ai_label_id']);
            $table->dropColumn('ai_label_id');
        });
    }
};
