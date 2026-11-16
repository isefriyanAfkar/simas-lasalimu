<?php

namespace Database\Factories;

use App\Domains\Academic\Enums\JenisSemester;
use App\Domains\Academic\Models\Semester;
use App\Domains\Academic\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dates follow the split TahunAjaranService makes, so a semester always lies
 * inside its school year: Ganjil from the school year's start to 31 December
 * of the first year, Genap from 1 January of the second year to its end.
 * Use it with TahunAjaran::factory() rows, which have no semesters yet.
 *
 * @extends Factory<Semester>
 */
#[UseModel(Semester::class)]
class SemesterFactory extends Factory
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
            'jenis' => JenisSemester::Ganjil,
            'tanggal_mulai' => fn (array $attributes): string => $this->defaultRange($attributes)[0],
            'tanggal_selesai' => fn (array $attributes): string => $this->defaultRange($attributes)[1],
            'status_aktif' => false,
        ];
    }

    public function genap(): static
    {
        return $this->state(['jenis' => JenisSemester::Genap]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{string, string}
     */
    private function defaultRange(array $attributes): array
    {
        $tahunAjaran = TahunAjaran::query()->findOrFail($attributes['tahun_ajaran_id']);
        $tahunPertama = $tahunAjaran->tanggal_mulai->year;
        $jenis = $attributes['jenis'] instanceof JenisSemester ? $attributes['jenis'] : JenisSemester::from($attributes['jenis']);

        return $jenis === JenisSemester::Ganjil
            ? [$tahunAjaran->tanggal_mulai->toDateString(), "{$tahunPertama}-12-31"]
            : [($tahunPertama + 1).'-01-01', $tahunAjaran->tanggal_selesai->toDateString()];
    }
}
