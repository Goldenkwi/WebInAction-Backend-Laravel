<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Categories;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeder master data instansi + pemetaan kategori default.
 *
 * Data ini membuat demo dashboard instansi langsung terisi dan membuat
 * rekomendasi disposisi AI -> kategori -> instansi bisa berjalan tanpa
 * melatih ulang model.
 *
 * Urutan pemanggilan di DatabaseSeeder: CategoriesSeeder lalu AgencySeeder,
 * karena pemetaan di bawah membutuhkan baris categories.
 */
class AgencySeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $agencies = [
            [
                'acronym' => 'DPUPR',
                'name' => 'Dinas Pekerjaan Umum dan Penataan Ruang',
                'description' => 'Menangani infrastruktur jalan, jembatan, drainase, penerangan jalan umum, dan tata ruang.',
                'contact_email' => 'pengaduan@dpupr.go.id',
                'contact_phone' => '0811-2233-4455',
                'address' => 'Jl. Merdeka No. 10, Kompleks Perkantoran Pemerintah',
                'sla_days' => 5,
            ],
            [
                'acronym' => 'DLHK',
                'name' => 'Dinas Lingkungan Hidup dan Kebersihan',
                'description' => 'Menangani kebersihan, pengelolaan sampah, pencemaran, dan pelestarian lingkungan.',
                'contact_email' => 'pengaduan@dlhk.go.id',
                'contact_phone' => '0811-2233-4466',
                'address' => 'Jl. Melati No. 21',
                'sla_days' => 3,
            ],
            [
                'acronym' => 'SATPOLPP',
                'name' => 'Satuan Polisi Pamong Praja',
                'description' => 'Menangani ketertiban umum, keamanan lingkungan, dan penegakan peraturan daerah.',
                'contact_email' => 'pengaduan@satpolpp.go.id',
                'contact_phone' => '0811-2233-4477',
                'address' => 'Jl. Ahmad Yani No. 5',
                'sla_days' => 1,
            ],
            [
                'acronym' => 'DINKES',
                'name' => 'Dinas Kesehatan',
                'description' => 'Menangani layanan puskesmas, rumah sakit daerah, kesehatan masyarakat, dan gizi.',
                'contact_email' => 'pengaduan@dinkes.go.id',
                'contact_phone' => '0811-2233-4488',
                'address' => 'Jl. Kesehatan No. 8',
                'sla_days' => 2,
            ],
            [
                'acronym' => 'DISHUB',
                'name' => 'Dinas Perhubungan',
                'description' => 'Menangani transportasi umum, lalu lintas, terminal, halte, dan perparkiran.',
                'contact_email' => 'pengaduan@dishub.go.id',
                'contact_phone' => '0811-2233-4499',
                'address' => 'Jl. Stasiun No. 3',
                'sla_days' => 3,
            ],
            [
                'acronym' => 'DISDIK',
                'name' => 'Dinas Pendidikan',
                'description' => 'Menangani sekolah negeri, sarana pendidikan, kurikulum, dan kesejahteraan peserta didik.',
                'contact_email' => 'pengaduan@disdik.go.id',
                'contact_phone' => '0811-2233-4500',
                'address' => 'Jl. Pendidikan No. 17',
                'sla_days' => 5,
            ],
            [
                'acronym' => 'DISKOMINFO',
                'name' => 'Dinas Komunikasi dan Informatika',
                'description' => 'Menangani jaringan internet publik, sistem informasi, dan layanan komunikasi pemerintah.',
                'contact_email' => 'pengaduan@diskominfo.go.id',
                'contact_phone' => '0811-2233-4511',
                'address' => 'Jl. Digital No. 1',
                'sla_days' => 3,
            ],
            [
                'acronym' => 'BPKAD',
                'name' => 'Badan Pengelolaan Keuangan dan Aset Daerah',
                'description' => 'Menangani pengelolaan keuangan daerah, aset, dan informasi anggaran publik.',
                'contact_email' => 'pengaduan@bpkad.go.id',
                'contact_phone' => '0811-2233-4522',
                'address' => 'Jl. Keuangan No. 12',
                'sla_days' => 7,
            ],
            [
                'acronym' => 'DPMPTSP',
                'name' => 'Dinas Penanaman Modal dan Pelayanan Terpadu Satu Pintu',
                'description' => 'Menangani perizinan, pelayanan publik terpadu, dan informasi prosedur administrasi.',
                'contact_email' => 'pengaduan@dpmptsp.go.id',
                'contact_phone' => '0811-2233-4533',
                'address' => 'Jl. Pelayanan No. 9',
                'sla_days' => 3,
            ],
            [
                'acronym' => 'DINSOS',
                'name' => 'Dinas Sosial',
                'description' => 'Menangani bantuan sosial, pemberdayaan masyarakat, dan perlindungan sosial.',
                'contact_email' => 'pengaduan@dinsos.go.id',
                'contact_phone' => '0811-2233-4544',
                'address' => 'Jl. Sosial No. 6',
                'sla_days' => 5,
            ],
            [
                'acronym' => 'SETDA',
                'name' => 'Sekretariat Daerah',
                'description' => 'Mengoordinasikan laporan yang belum memiliki instansi teknis yang spesifik.',
                'contact_email' => 'pengaduan@setda.go.id',
                'contact_phone' => '0811-2233-4555',
                'address' => 'Jl. Pemerintahan No. 1',
                'sla_days' => 7,
            ],
        ];

        foreach ($agencies as $agency) {
            Agency::updateOrCreate(
                ['acronym' => $agency['acronym']],
                $agency + ['is_active' => true]
            );
        }

        // ai_label_id model kategori -> singkatan instansi default.
        $categoryAgencyMap = [
            0 => 'DPUPR',      // Infrastruktur
            1 => 'DLHK',       // Kebersihan
            2 => 'SATPOLPP',   // Keamanan
            3 => 'DINKES',     // Kesehatan
            4 => 'DISHUB',     // Transportasi
            5 => 'DISDIK',     // Pendidikan
            6 => 'DISKOMINFO', // IT & Jaringan
            7 => 'BPKAD',      // Keuangan
            8 => 'DPMPTSP',    // Pelayanan Publik
            9 => 'DLHK',       // Lingkungan
            10 => 'DINSOS',    // Sosial
            11 => 'SETDA',     // Lain-lain
        ];

        $agencyIds = Agency::pluck('id', 'acronym');

        foreach ($categoryAgencyMap as $labelId => $acronym) {
            Categories::where('ai_label_id', $labelId)->update([
                'default_agency_id' => $agencyIds[$acronym] ?? null,
            ]);
        }
    }
}
