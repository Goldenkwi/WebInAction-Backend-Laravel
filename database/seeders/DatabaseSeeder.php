<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder utama.
 *
 * Mengisi:
 *  - 12 kategori utama (CategoriesSeeder)
 *  - 3 akun dummy untuk demo kompetisi
 *  - 12 laporan demo (semua status + prioritas) beserta audit trail-nya
 *
 * Password semua akun dummy: "password123"
 * (dicek dengan Hash::check, bukan perbandingan string biasa).
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Urutan penting: kategori -> user -> laporan.
        // ReportsSeeder butuh user (pelapor & admin) untuk mengisi audit trail.
        $this->call(CategoriesSeeder::class);

        $users = [
            [
                'name' => 'Warga Demo',
                'email' => 'warga@example.com',
                'role' => User::ROLE_CITIZEN,
            ],
            [
                'name' => 'Admin Verifikator',
                'email' => 'verifier@example.com',
                'role' => User::ROLE_VERIFIER,
            ],
            [
                'name' => 'Admin Instansi',
                'email' => 'agency@example.com',
                'role' => User::ROLE_AGENCY,
            ],
        ];

        foreach ($users as $user) {
            // firstOrCreate: kalau email sudah ada, jangan diduplikasi.
            User::firstOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    // Di-hash manual di seeder karena di sini kita memakai
                    // firstOrCreate (bukan create lewat mass-assignment).
                    'password' => Hash::make('password123'),
                    'role' => $user['role'],
                ]
            );
        }

        // Laporan demo dibuat terakhir karena butuh user + kategori.
        $this->call(ReportsSeeder::class);
    }
}
