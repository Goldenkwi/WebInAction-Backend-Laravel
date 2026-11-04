<?php

namespace Database\Seeders;

use App\Models\Agency;
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
        // Urutan penting: kategori -> instansi -> user -> laporan.
        // AgencySeeder memetakan categories.default_agency_id, jadi harus
        // berjalan SETELAH kategori dibuat.
        $this->call(CategoriesSeeder::class);
        $this->call(AgencySeeder::class);

        $agencyId = Agency::where('acronym', 'DPUPR')->value('id');

        $users = [
            [
                'name' => 'Warga Demo',
                'email' => 'warga@example.com',
                'role' => User::ROLE_CITIZEN,
                'agency_id' => null,
            ],
            [
                'name' => 'Admin Verifikator',
                'email' => 'verifier@example.com',
                'role' => User::ROLE_VERIFIER,
                'agency_id' => null,
            ],
            [
                'name' => 'Admin Instansi',
                'email' => 'agency@example.com',
                'role' => User::ROLE_AGENCY,
                'agency_id' => $agencyId,
            ],
        ];

        foreach ($users as $user) {
            // firstOrCreate: kalau email sudah ada, jangan diduplikasi.
            $created = User::firstOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    // Di-hash manual di seeder karena di sini kita memakai
                    // firstOrCreate (bukan create lewat mass-assignment).
                    'password' => Hash::make('password123'),
                    'role' => $user['role'],
                    'agency_id' => $user['agency_id'],
                ]
            );

            // Data lama (sebelum tabel agencies ada) belum punya agency_id.
            if ($user['role'] === User::ROLE_AGENCY && $created->agency_id === null) {
                $created->forceFill(['agency_id' => $user['agency_id']])->save();
            }
        }

        // Laporan demo dibuat terakhir karena butuh user + kategori.
        $this->call(ReportsSeeder::class);
    }
}
