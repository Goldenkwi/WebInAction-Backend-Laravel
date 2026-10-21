<?php

namespace Database\Seeders;

use App\Models\Categories;
use App\Models\Reports;
use App\Models\ReportsLog;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeder 12 laporan demo.
 *
 * ---------------------------------------------------------------------------
 * TUJUAN
 * ---------------------------------------------------------------------------
 * 1. Dashboard admin TIDAK kosong saat demo -> 4 kartu metrik & pie chart
 *    langsung punya angka yang masuk akal.
 * 2. Timeline (FR-PUB-02) kelihatan isinya: laporan dengan status
 *    `followed_up` punya 3 baris log (pending -> verified -> followed_up).
 * 3. Semua jenis kasus tercover:
 *    - laporan publik (verified / followed_up, tidak privat)
 *    - laporan privat (tidak muncul di halaman publik)
 *    - laporan anonim (nama pelapor = "Anonymous")
 *    - laporan pending (masih antrean verifikasi)
 *    - laporan rejected (ditolak, dengan alasannya)
 *    - laporan dengan koreksi admin terhadap tebakan AI (FR-ADM-03)
 *    - laporan dengan berbagai level prioritas
 *
 * Tanggal dibuatnya laporan sengaja disebar (tgl 1-12) supaya rata-rata
 * waktu penyelesaian di dashboard tidak bernilai 0.
 */
class ReportsSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $citizen = User::where('email', 'warga@example.com')->first();
        $verifier = User::where('email', 'verifier@example.com')->first();
        $agency = User::where('email', 'agency@example.com')->first();

        if (! $citizen || ! $verifier || ! $agency) {
            $this->command?->warn('User dummy belum ada, ReportsSeeder dilewati.');

            return;
        }

        // helper: cari kategori berdasarkan label model
        $cat = fn (int $labelId) => Categories::where('ai_label_id', $labelId)->first()?->id;

        $reports = [
            // ---------------- STATUS: followed_up (sudah selesai) ----------------
            [
                'ticket' => 'LPR-'.now()->format('Y').'-0001',
                'cat' => $cat(0),   // Infrastruktur
                'title' => 'Lampu jalan mati dan kabel menjuntai di dekat SDN 03',
                'desc' => 'Sudah 3 malam lampu PJU padam total dan ada kabel turun yang membahayakan anak sekolah.',
                'type' => 'pengaduan',
                'location' => 'Jl. Telekomunikasi No. 12',
                'days_ago' => 12,
                'conf' => 0.91,
                'priority' => 'tinggi',
                'pconf' => 0.83,
                'anon' => false,
                'private' => false,
                'status' => Reports::STATUS_FOLLOWED_UP,
                'logs' => [
                    [Reports::STATUS_PENDING,     null,      'Klasifikasi otomatis ke kategori Infrastruktur (Confidence: 91%). Prioritas: Tinggi (Confidence: 83%).'],
                    [Reports::STATUS_VERIFIED,    'verifier', 'Mohon tim teknis penerangan jalan segeraDiamankan kabel karena dekat area sekolah.'],
                    [Reports::STATUS_FOLLOWED_UP, 'agency',   'Lampu PJU sudah diganti dan kabel telah diamankan. Kondisi sudah normal kembali.'],
                ],
            ],
            [
                'ticket' => 'LPR-'.now()->format('Y').'-0002',
                'cat' => $cat(1),   // Kebersihan
                'title' => 'Tempat sampah penuh meluap di Pasar Malam',
                'desc' => 'Bak sampah di sisi timur pasar malam sudah penuh dan meluap, aroma tidak sedap mengganggu warga.',
                'type' => 'pengaduan',
                'location' => 'Pasar Malam, Jl. Merdeka',
                'days_ago' => 9,
                'conf' => 0.78,
                'priority' => 'sedang',
                'pconf' => 0.66,
                'anon' => false,
                'private' => false,
                'status' => Reports::STATUS_FOLLOWED_UP,
                'logs' => [
                    [Reports::STATUS_PENDING,     null,      'Klasifikasi otomatis ke kategori Kebersihan (Confidence: 78%). Prioritas: Sedang (Confidence: 66%). Menunggu verifikasi manual.'],
                    [Reports::STATUS_VERIFIED,    'verifier', 'Diteruskan ke Dinas Kebersihan untuk pengangkutan harian tambahan.'],
                    [Reports::STATUS_FOLLOWED_UP, 'agency',   'Pengangkutan tambahan 3 kali/week sudah enacted. Tempat sampah kosong dan dibersihkan.'],
                ],
            ],
            [
                'ticket' => 'LPR-'.now()->format('Y').'-0003',
                'cat' => $cat(3),   // Kesehatan
                'title' => 'Puskesmas tutup tidak sesuai jam praktik',
                'desc' => 'Puskesmas tutup jam 2 siang padahal/meta jadwal sampai jam 5 sore, warga yang sakit tidak tertangani.',
                'type' => 'pengaduan',
                'location' => 'Puskesmas Kec. Integrity, Jl. Melati',
                'days_ago' => 7,
                'conf' => 0.88,
                'priority' => 'tinggi',
                'pconf' => 0.79,
                'anon' => true,
                'private' => false,
                'status' => Reports::STATUS_FOLLOWED_UP,
                'logs' => [
                    [Reports::STATUS_PENDING,     null,      'Klasifikasi otomatis ke kategori Kesehatan (Confidence: 88%). Prioritas: Tinggi (Confidence: 79%).'],
                    [Reports::STATUS_VERIFIED,    'verifier', 'Shift pagi sudah diperbaiki. Jadwal praktik ditata ulang dan diinformasikan ke warga.'],
                    [Reports::STATUS_FOLLOWED_UP, 'agency',   'Jadwal baru sudah ditempel di papan informasi dan diumumkan ke RT.'],
                ],
            ],

            // ---------------- STATUS: verified (sudah diverifikasi) ----------------
            [
                'ticket' => 'LPR-'.now()->format('Y').'-0004',
                'cat' => $cat(4),   // Transportasi
                'title' => 'Jadwal shuttle bus tidak pernah sesuai',
                'desc' => 'Jadwal shuttle bus sering molor berjam-jam, penumpang menunggu lama di halte.',
                'type' => 'pengaduan',
                'location' => 'Halte Bus, Stasiun Kota',
                'days_ago' => 5,
                'conf' => 0.72,
                'priority' => 'sedang',
                'pconf' => 0.61,
                'anon' => false,
                'private' => false,
                'status' => Reports::STATUS_VERIFIED,
                'logs' => [
                    [Reports::STATUS_PENDING,  null,      'Klasifikasi otomatis ke kategori Transportasi (Confidence: 72%). Prioritas: Sedang (Confidence: 61%). Menunggu verifikasi manual.'],
                    [Reports::STATUS_VERIFIED, 'verifier', 'Diusulkan penjadwalan ulang dan penambahan armada shuttle di jam sibuk.'],
                ],
            ],
            [
                'ticket' => 'LPR-'.now()->format('Y').'-0005',
                'cat' => $cat(5),   // Pendidikan
                'title' => 'Media ajar tidak tersedia di kelas',
                'desc' => 'Media ajar tidak tersedia di kelas, tolong buka kelas tambahan karena kuota cepat habis.',
                'type' => 'pengaduan',
                'location' => 'Sekretariat Prodi Teknik Industri',
                'days_ago' => 4,
                'conf' => 0.69,
                'priority' => 'rendah',
                'pconf' => 0.55,
                'anon' => false,
                'private' => false,
                'status' => Reports::STATUS_VERIFIED,
                'logs' => [
                    [Reports::STATUS_PENDING,  null,      'Klasifikasi otomatis ke kategori Pendidikan (Confidence: 69%). Prioritas: Rendah (Confidence: 55%). Menunggu verifikasi manual.'],
                    [Reports::STATUS_VERIFIED, 'verifier', 'Diteruskan ke Bagian Akademik untuk penambahan kelas dan penyediaan media ajar.'],
                ],
            ],
            [
                'ticket' => 'LPR-'.now()->format('Y').'-0006',
                'cat' => $cat(2),   // Keamanan
                'title' => 'Preman minta jatah parkir di depanGOR badminton',
                'desc' => 'Makin tidak aman aja, sering kejadian preman meminta jatah parkir di depanGOR badminton.',
                'type' => 'pengaduan',
                'location' => 'GOR badminton, Komplek Sport Center',
                'days_ago' => 3,
                'conf' => 0.94,
                'priority' => 'tinggi',
                'pconf' => 0.87,
                'anon' => false,
                'private' => false,
                'status' => Reports::STATUS_VERIFIED,
                'logs' => [
                    [Reports::STATUS_PENDING,  null,      'Klasifikasi otomatis ke kategori Keamanan (Confidence: 94%). Prioritas: Tinggi (Confidence: 87%). Rekomendasi AI sangat yakin, menunggu konfirmasi admin.'],
                    [Reports::STATUS_VERIFIED, 'verifier', 'Mohon Satpam Sport Center menambah patroli di area parkir dan penerangan.'],
                ],
            ],

            // ---------------- STATUS: pending (menunggu verifikasi) ----------------
            [
                'ticket' => 'LPR-'.now()->format('Y').'-0007',
                'cat' => $cat(6),   // IT & Jaringan
                'title' => 'Wi-Fi di ruang tunggu sering terputus-putus',
                'desc' => 'Wi-Fi di ruang tunggu sering terputus-putus, tidak bisa dipakai untuk fill data. Wi-Fi di ruang tunggu sering terputus-putus.',
                'type' => 'aspirasi',
                'location' => 'Ruang Tunggu Layanan',
                'days_ago' => 2,
                'conf' => 0.55,
                'priority' => 'rendah',
                'pconf' => 0.41,
                'anon' => false,
                'private' => false,
                'status' => Reports::STATUS_PENDING,
                'logs' => [
                    [Reports::STATUS_PENDING, null, 'Klasifikasi otomatis ke kategori IT & Jaringan (Confidence: 55%). Prioritas: Rendah (Confidence: 41%). Menunggu verifikasi manual.'],
                ],
            ],
            [
                'ticket' => 'LPR-'.now()->format('Y').'-0008',
                'cat' => $cat(8),   // Pelayanan Publik
                'title' => 'Proses perizinan terlalu lama',
                'desc' => 'Proses perizinan sudah 2 minggu belum ada perkembangan sama sekali.',
                'type' => 'aspirasi',
                'location' => 'Kantor Kepala Desa',
                'days_ago' => 1,
                'conf' => 0.61,
                'priority' => 'sedang',
                'pconf' => 0.58,
                'anon' => false,
                'private' => true,   // PRIVAT: tidak muncul di halaman publik
                'status' => Reports::STATUS_PENDING,
                'logs' => [
                    [Reports::STATUS_PENDING, null, 'Klasifikasi otomatis ke kategori Pelayanan Publik (Confidence: 61%). Prioritas: Sedang (Confidence: 58%). Menunggu verifikasi manual.'],
                ],
            ],

            // ---------------- STATUS: rejected (ditolak) ----------------
            [
                'ticket' => 'LPR-'.now()->format('Y').'-0009',
                'cat' => $cat(11),  // Lain-lain
                'title' => 'Aduan tidak jelas kategorinya',
                'desc' => 'Di meja bantuan, aduan tidak jelas kategorinya, mohon bantuan untuk diteruskan ke instansi yang berwenang.',
                'type' => 'aspirasi',
                'location' => 'Meja Bantuan',
                'days_ago' => 8,
                'conf' => 0.35,
                'priority' => 'rendah',
                'pconf' => 0.30,
                'anon' => true,
                'private' => false,
                'status' => Reports::STATUS_REJECTED,
                'logs' => [
                    [Reports::STATUS_PENDING,  null,      'Klasifikasi otomatis ke kategori Lain-lain (Confidence: 35%). Prioritas: Rendah (Confidence: 30%). Menunggu verifikasi manual.'],
                    [Reports::STATUS_REJECTED, 'verifier', 'Konten tidak menjelaskan masalah spesifik dan tidak dapat diverifikasi. Silakan kirim ulang dengan detail lokasi dan kronologi kejadian.'],
                ],
            ],

            // ---------------- KOREKSI ADMIN (FR-ADM-03) ----------------
            [
                'ticket' => 'LPR-'.now()->format('Y').'-0010',
                'cat' => $cat(9),   // Lingkungan
                'ai_cat' => $cat(0),   // AI salah tebak -> Infrastruktur
                'title' => 'Pohon tumbang dan genangan air di gang',
                'desc' => 'Pohon tumbang dan genangan air di gang, meski sudah 3 hari belum ada penanganan dari terkait.',
                'type' => 'pengaduan',
                'location' => 'Gang Melati RT 04',
                'days_ago' => 6,
                'conf' => 0.58,
                'priority' => 'tinggi',
                'pconf' => 0.74,
                'anon' => false,
                'private' => false,
                'status' => Reports::STATUS_VERIFIED,
                'logs' => [
                    [Reports::STATUS_PENDING,  null,      'Klasifikasi otomatis ke kategori Infrastruktur (Confidence: 58%). Prioritas: Tinggi (Confidence: 74%). Menunggu verifikasi manual.'],
                    [Reports::STATUS_VERIFIED, 'verifier', 'Tebakan AI Infrastruktur dikoreksi menjadi Lingkungan karena masalah utama adalah pohon tumbang & genangan. Mohon tim pemeliharaan segera menebang pohon.'],
                ],
            ],

            // ---------------- LAIN-LAIN ----------------
            [
                'ticket' => 'LPR-'.now()->format('Y').'-0011',
                'cat' => $cat(7),   // Keuangan
                'title' => 'Informasi cara pengajuan bantuan sosial',
                'desc' => 'Mohon informasi lebih lanjut mengenai prosedur pengajuan bantuan sosial untuk warga kurang mampu.',
                'type' => 'aspirasi',
                'location' => 'Kantor Kepala Desa',
                'days_ago' => 10,
                'conf' => 0.83,
                'priority' => 'rendah',
                'pconf' => 0.62,
                'anon' => false,
                'private' => false,
                'status' => Reports::STATUS_VERIFIED,
                'logs' => [
                    [Reports::STATUS_PENDING,  null,      'Klasifikasi otomatis ke kategori Keuangan (Confidence: 83%). Prioritas: Rendah (Confidence: 62%).'],
                    [Reports::STATUS_VERIFIED, 'verifier', 'Diteruskan ke Bagian Welfare untuk informasi prosedur pengajuan bantuan.'],
                ],
            ],
            [
                'ticket' => 'LPR-'.now()->format('Y').'-0012',
                'cat' => $cat(10),  // Sosial
                'title' => 'Kegiatan Posyandu perlu dukungan warga',
                'desc' => 'Kegiatan Posyandu perlu dukungan volunteers agar bisa berjalan rutin setiap bulan.',
                'type' => 'aspirasi',
                'location' => 'RT 07',
                'days_ago' => 11,
                'conf' => 0.76,
                'priority' => 'sedang',
                'pconf' => 0.68,
                'anon' => true,
                'private' => true,   // PRIVAT + ANONIM
                'status' => Reports::STATUS_PENDING,
                'logs' => [
                    [Reports::STATUS_PENDING, null, 'Klasifikasi otomatis ke kategori Sosial (Confidence: 76%). Prioritas: Sedang (Confidence: 68%). Menunggu verifikasi manual.'],
                ],
            ],
        ];

        foreach ($reports as $data) {
            $createdAt = Carbon::now()->subDays($data['days_ago'])->setTime(9, 30);

            // forceCreate: created_at/updated_at TIDAK termasuk $fillable,
            // jadi kalau pakai create()() timestamp custom akan diabaikan.
            $report = Reports::forceCreate([
                'ticket_number' => $data['ticket'],
                'user_id' => $citizen->id,
                'category_id' => $data['cat'],
                'ai_pred_cat_id' => $data['ai_cat'] ?? $data['cat'],
                'ai_confidence' => $data['conf'],
                'ai_priority' => $data['priority'],
                'ai_priority_confidence' => $data['pconf'],
                'priority' => $data['priority'],
                'report_type' => $data['type'],
                'title' => $data['title'],
                'description' => $data['desc'],
                'incident_date' => $data['type'] === 'pengaduan'
                    ? $createdAt->copy()->subDays(2)->toDateString()
                    : null,
                'location_text' => $data['location'],
                'image_url' => null,
                'is_anonymous' => $data['anon'],
                'is_private' => $data['private'],
                'status' => $data['status'],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            // Audit trail. Log ke-2 (verified) dibuat 6 jam setelah laporan,
            // log ke-3 (followed_up) 2 hari setelahnya -> supaya rata-rata
            // waktu penyelesaian di dashboard punya angka yang masuk akal.
            foreach ($data['logs'] as [$status, $actor, $note]) {
                $logAt = match ($status) {
                    Reports::STATUS_PENDING => $createdAt,
                    Reports::STATUS_VERIFIED => $createdAt->copy()->addHours(6),
                    Reports::STATUS_FOLLOWED_UP => $createdAt->copy()->addDays(2),
                    Reports::STATUS_REJECTED => $createdAt->copy()->addHours(10),
                    default => $createdAt,
                };

                ReportsLog::forceCreate([
                    'report_id' => $report->id,
                    'changed_by' => $actor === 'verifier' ? $verifier->id : ($actor === 'agency' ? $agency->id : null),
                    'status' => $status,
                    'note' => $note,
                    'proof_img' => null,
                    'created_at' => $logAt,
                    'updated_at' => $logAt,
                ]);
            }
        }
    }
}
