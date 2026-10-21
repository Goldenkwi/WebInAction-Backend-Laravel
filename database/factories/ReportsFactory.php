<?php

namespace Database\Factories;

use App\Models\Categories;
use App\Models\Reports;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory untuk data laporan (dipakai test & seeding cepat).
 *
 * @extends Factory<Reports>
 */
class ReportsFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $createdAt = now()->subDays(fake()->numberBetween(1, 30));

        return [
            'ticket_number' => 'LPR-'.now()->format('Y').'-'.Str::upper(Str::random(4)),
            'user_id' => User::factory(),
            'category_id' => Categories::factory(),
            'ai_pred_cat_id' => null,
            'ai_confidence' => fake()->randomFloat(2, 0.3, 0.99),
            'ai_priority' => fake()->randomElement(['rendah', 'sedang', 'tinggi', 'darurat']),
            'ai_priority_confidence' => fake()->randomFloat(2, 0.3, 0.99),
            'priority' => fake()->randomElement(['rendah', 'sedang', 'tinggi', 'darurat']),
            'report_type' => fake()->randomElement(['pengaduan', 'aspirasi']),
            'title' => fake()->sentence(6),
            'description' => fake()->paragraph(3),
            'incident_date' => $createdAt->copy()->subDay()->toDateString(),
            'image_url' => null,
            'location_text' => 'Jl. Contoh No. '.$createdAt->format('Y'),
            'is_anonymous' => false,
            'is_private' => false,
            'status' => Reports::STATUS_PENDING,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /**
     * Laporan yang sudah diverifikasi (publik).
     */
    public function verified(): static
    {
        return $this->state(fn () => ['status' => Reports::STATUS_VERIFIED]);
    }

    /**
     * Laporan yang sudah ditindaklanjuti.
     */
    public function followedUp(): static
    {
        return $this->state(fn () => ['status' => Reports::STATUS_FOLLOWED_UP]);
    }

    /**
     * Laporan anonim.
     */
    public function anonymous(): static
    {
        return $this->state(fn () => ['is_anonymous' => true]);
    }

    /**
     * Laporan privat (tidak muncul di halaman publik).
     */
    public function private(): static
    {
        return $this->state(fn () => ['is_private' => true]);
    }
}
