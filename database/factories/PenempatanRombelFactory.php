<?php

namespace Database\Factories;

use App\Domains\Academic\Models\PenempatanRombel;
use App\Domains\Academic\Models\Rombel;
use App\Domains\People\Models\Siswa;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Plain rows; PenempatanRombelService checks capacity, status and the school
 * year, the factory does not. tahun_ajaran_id always follows the rombel.
 *
 * @extends Factory<PenempatanRombel>
 */
#[UseModel(PenempatanRombel::class)]
class PenempatanRombelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'siswa_id' => Siswa::factory(),
            'rombel_id' => Rombel::factory(),
            'tahun_ajaran_id' => fn (array $attributes): int => (int) Rombel::query()->whereKey($attributes['rombel_id'])->value('tahun_ajaran_id'),
        ];
    }
}
