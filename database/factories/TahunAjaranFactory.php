<?php

namespace Database\Factories;

use App\Domains\Academic\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Plain rows for tests that need a TahunAjaran without its semesters. Real
 * school years, with both semesters, are created by TahunAjaranService.
 *
 * The years are not random: they are counted from 2100 up, one per school
 * year made, for the whole test process. So two school years from the
 * factory never share a name, and never clash with a year a test names
 * itself. Years named explicitly in tests must therefore stay below 2100.
 * With parallel tests every process counts on its own, in its own database.
 *
 * @extends Factory<TahunAjaran>
 */
#[UseModel(TahunAjaran::class)]
class TahunAjaranFactory extends Factory
{
    private const int TAHUN_PERTAMA = 2100;

    private static int $tahunBerikutnya = self::TAHUN_PERTAMA;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tahunPertama = self::$tahunBerikutnya++;

        return [
            'nama' => $tahunPertama.'/'.($tahunPertama + 1),
            'tanggal_mulai' => "{$tahunPertama}-07-01",
            'tanggal_selesai' => ($tahunPertama + 1).'-06-30',
            'status_aktif' => false,
        ];
    }
}
