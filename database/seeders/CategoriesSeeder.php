<?php

namespace Database\Seeders;

use App\Models\Categories;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeder 12 kategori utama.
 *
 * ---------------------------------------------------------------------------
 * PENTING: NAMA HARUS PERSIS SAMA DENGAN id2label MODEL AI
 * ---------------------------------------------------------------------------
 * Model IndoBERT P2 fine-tuned (model kategori) punya 12 label:
 *
 *     0 Infrastruktur      1 Kebersihan       2 Keamanan        3 Kesehatan
 *     4 Transportasi       5 Pendidikan        6 IT & Jaringan   7 Keuangan
 *     8 Pelayanan Publik   9 Lingkungan       10 Sosial        11 Lain-lain
 *
 * Kolom `ai_label_id` pada tabel categories menyatakan label model mana yang
 * diwakili tiap kategori. Dengan begitu Laravel tidak perlu mencocokkan
 * string (yang rawan beda kapital/spasi) - cukup `where('ai_label_id', 0)`.
 *
 * Verifikasi kecocokan ini ada di command:
 *     php artisan ai:check-labels
 *
 * Catatan: daftar ini menggantikan 6 kategori awal (Infrastruktur, Keamanan,
 * Isu Sosial, Edukasi, Kesehatan, Lainnya) yang memakai nama yang tidak cocok
 * dengan label model.
 */
class CategoriesSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $categories = [
            ['ai_label_id' => 0,  'name' => 'Infrastruktur',    'icon' => '🛣️'],
            ['ai_label_id' => 1,  'name' => 'Kebersihan',       'icon' => '🗑️'],
            ['ai_label_id' => 2,  'name' => 'Keamanan',         'icon' => '🚨'],
            ['ai_label_id' => 3,  'name' => 'Kesehatan',        'icon' => '🏥'],
            ['ai_label_id' => 4,  'name' => 'Transportasi',     'icon' => '🚌'],
            ['ai_label_id' => 5,  'name' => 'Pendidikan',       'icon' => '🎓'],
            ['ai_label_id' => 6,  'name' => 'IT & Jaringan',    'icon' => '💻'],
            ['ai_label_id' => 7,  'name' => 'Keuangan',         'icon' => '💰'],
            ['ai_label_id' => 8,  'name' => 'Pelayanan Publik', 'icon' => '🏛️'],
            ['ai_label_id' => 9,  'name' => 'Lingkungan',       'icon' => '🌳'],
            ['ai_label_id' => 10, 'name' => 'Sosial',           'icon' => '🤝'],
            ['ai_label_id' => 11, 'name' => 'Lain-lain',        'icon' => '📦'],
        ];

        foreach ($categories as $category) {
            // updateOrCreate = seeder bisa dijalankan berulang tanpa error
            // "duplicate entry" (idempotent).
            Categories::updateOrCreate(
                ['ai_label_id' => $category['ai_label_id']],
                ['name' => $category['name'], 'icon' => $category['icon']]
            );
        }
    }
}
