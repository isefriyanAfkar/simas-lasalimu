<?php

namespace Database\Factories;

use App\Domains\People\Models\StatusKepegawaian;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Rows that already follow StatusKepegawaianService's rules: a unique name of
 * at most 50 characters that never clashes with the statuses the migration
 * fills (PNS, PPPK, GTT, PTT, GTBPNS).
 *
 * @extends Factory<StatusKepegawaian>
 */
#[UseModel(StatusKepegawaian::class)]
class StatusKepegawaianFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nama' => 'Status '.fake()->unique()->numerify('####'),
            'urutan' => null,
            'status_aktif' => true,
        ];
    }

    public function nonaktif(): static
    {
        return $this->state(['status_aktif' => false]);
    }
}
