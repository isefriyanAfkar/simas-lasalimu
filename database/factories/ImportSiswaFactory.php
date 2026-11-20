<?php

namespace Database\Factories;

use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\StatusImportSiswa;
use App\Domains\People\Models\ImportSiswa;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A freshly uploaded import waiting for its check. The file itself is not
 * created; tests that read it put one at path.
 *
 * @extends Factory<ImportSiswa>
 */
#[UseModel(ImportSiswa::class)]
class ImportSiswaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tahun_ajaran_id' => TahunAjaran::factory(),
            'nama_file' => 'siswa.xlsx',
            'path' => 'imports/'.Str::uuid().'.xlsx',
            'status' => StatusImportSiswa::Menunggu,
            'ringkasan' => null,
            'galat' => null,
            'pindah' => null,
            'pesan' => null,
            'diproses_sejak' => null,
            'selesai_pada' => null,
        ];
    }
}
