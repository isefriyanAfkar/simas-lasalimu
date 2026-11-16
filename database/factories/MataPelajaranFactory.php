<?php

namespace Database\Factories;

use App\Domains\Academic\Models\MataPelajaran;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Rows that already follow MataPelajaranService's rules: kode is 2 to 10
 * uppercase letters and digits, both kode and nama are unique.
 *
 * @extends Factory<MataPelajaran>
 */
#[UseModel(MataPelajaran::class)]
class MataPelajaranFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kode' => fake()->unique()->regexify('[A-Z]{3}[0-9]{3}'),
            'nama' => 'Mata Pelajaran '.fake()->unique()->numerify('####'),
            'urutan' => null,
            'status_aktif' => true,
        ];
    }

    public function nonaktif(): static
    {
        return $this->state(['status_aktif' => false]);
    }
}
