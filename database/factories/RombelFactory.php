<?php

namespace Database\Factories;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Plain rows; RombelService checks the school level, the factory does not.
 * Pass tingkat and nomor explicitly when a test depends on them.
 *
 * @extends Factory<Rombel>
 */
#[UseModel(Rombel::class)]
class RombelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tahun_ajaran_id' => TahunAjaran::factory(),
            'tingkat' => Tingkat::X,
            'nomor' => fake()->unique()->numberBetween(1, 99),
            'kapasitas' => null,
        ];
    }
}
