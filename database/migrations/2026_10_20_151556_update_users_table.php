<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan kolom role ke tabel users.
     *
     * Role sesuai SRS Bab 1 (4 aktor):
     *   citizen        -> masyarakat pelapor
     *   verifier_admin -> admin verifikator (cek tebakan AI, verifikasi/tolak)
     *   agency_admin   -> admin instansi (tindaklanjuti laporan terverifikasi)
     *
     * Tips efisiensi tim 2 orang (SRS): verifier_admin & agency_admin boleh
     * digabung jadi satu role "admin" di UI, asalkan alur tombol tetap
     * Verifikasi -> Tindak Lanjut. Kalau digabung, cukup tambah satu nilai
     * 'admin' ke daftar enum di bawah.
     *
     * Default 'citizen' supaya user yang dibuat tanpa role eksplisit
     * (mis. dari factory) tidak gagal saat insert.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['citizen', 'verifier_admin', 'agency_admin'])
                ->default('citizen')
                ->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
