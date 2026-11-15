<?php

namespace Database\Factories;

use App\Domains\Academic\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Plain rows for tests that need a TahunAjaran without its semesters. Real
 * school years, with both semesters, are created by TahunAjaranService.
 *
 * @extends Factory<TahunAjaran>
 */
#[UseModel(TahunAjaran::class)]
class TahunAjaranFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tahunPertama = fake()->unique()->numberBetween(2000, 2090);

        return [
            'nama' => $tahunPertama.'/'.($tahunPertama + 1),
            'tanggal_mulai' => "{$tahunPertama}-07-01",
            'tanggal_selesai' => ($tahunPertama + 1).'-06-30',
            'status_aktif' => false,
        ];
    }
}
