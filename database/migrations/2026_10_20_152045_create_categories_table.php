<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel categories = master data kategori masalah.
     *
     * Kolom:
     *  id    : primary key
     *  name  : nama kategori (label yang dipelajari AI BERT) - UNIQUE
     *  icon  : nama ikon / emoji (opsional), dipakai frontend
     *
     * Catatan: sengaja TIDAK ada kolom slug & color_hex (dihapus di ERD 4 tabel).
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('icon')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
