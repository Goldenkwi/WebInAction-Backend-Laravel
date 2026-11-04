<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel agencies = master data instansi/unit kerja.
     *
     * Satu instansi dapat menangani beberapa kategori laporan. Kategori
     * menyimpan instansi DEFAULT-nya di kolom categories.default_agency_id,
     * sedangkan penugasan final tetap disimpan di reports.assigned_agency_id.
     *
     * Kolom:
     *  name          : nama lengkap instansi (unik)
     *  acronym       : singkatan untuk badge UI, mis. DPUPR / DISHUB
     *  description   : ringkasan tupoksi untuk halaman informasi publik
     *  logo_url      : path file di disk `public` (bukan URL penuh)
     *  contact_email : email resmi instansi
     *  contact_phone : nomor telepon/WhatsApp call center
     *  address       : alamat kantor
     *  sla_days      : target hari penyelesaian laporan
     *  is_active     : nonaktifkan tanpa menghapus histori laporan
     */
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('acronym', 20)->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->unsignedTinyInteger('sla_days')->default(3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Dipakai untuk menyembunyikan instansi nonaktif dari dropdown.
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agencies');
    }
};
