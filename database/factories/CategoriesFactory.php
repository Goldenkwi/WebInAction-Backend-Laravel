<?php

namespace Database\Factories;

use App\Models\Categories;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory untuk testing & seeding cepat (php artisan tinker).
 *
 * Catatan: file ini sebelumnya menulis class "categories" (huruf kecil) yang
 * TIDAK ADA -> factory akan error. Class yang benar adalah "Categories".
 *
 * Catatan: nama kategori hasil factory diberi suffix angka supaya TIDAK
 * bentrok dengan unique index di tabel categories (yang dipakai CategoriesSeeder
 * untuk 12 kategori resmi).
 *
 * @extends Factory<Categories>
 */
class CategoriesFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Kategori '.fake()->unique()->numerify('####'),
            'icon' => fake()->randomElement(['🛣️', '🚨', '🤝', '🎓', '🏥', '📦']),

            // ai_label_id dibiarkan null supaya tidak bentrok dengan
            // CategoriesSeeder yang mengisi 0-11.
            'ai_label_id' => null,
        ];
    }
}
