<?php

namespace Database\Factories;

use App\Models\Reports;
use App\Models\ReportsLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory untuk baris audit trail.
 *
 * @extends Factory<ReportsLog>
 */
class ReportsLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report_id' => Reports::factory(),
            'changed_by' => User::factory(),
            'status' => Reports::STATUS_PENDING,
            'note' => fake()->sentence(10),
            'proof_img' => null,
        ];
    }

    /**
     * Log yang dibuat oleh sistem AI (tanpa user).
     */
    public function bySystem(): static
    {
        return $this->state(fn () => ['changed_by' => null]);
    }
}
