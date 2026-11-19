<?php

namespace Database\Factories;

use App\Domains\People\Enums\HubunganWali;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Models\SiswaWali;
use App\Domains\People\Models\WaliMurid;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Plain rows; SiswaWaliService checks the relation rules, the factory does
 * not. Pass hubungan and utama explicitly when a test depends on them.
 *
 * @extends Factory<SiswaWali>
 */
#[UseModel(SiswaWali::class)]
class SiswaWaliFactory extends Factory
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
            'wali_murid_id' => WaliMurid::factory(),
            'hubungan' => HubunganWali::Wali,
            'utama' => false,
        ];
    }
}
