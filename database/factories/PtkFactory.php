<?php

namespace Database\Factories;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\JenisPtk;
use App\Domains\People\Enums\StatusPtk;
use App\Domains\People\Models\Ptk;
use App\Domains\People\Models\StatusKepegawaian;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Rows that already follow PtkService's rules: an active guru on a fresh
 * account without a panel role, holding the role of its kind (the roles must
 * exist, see PeranDanIzinSeeder), on the PNS status the migration fills.
 *
 * @extends Factory<Ptk>
 */
#[UseModel(Ptk::class)]
class PtkFactory extends Factory
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
            'jenis_ptk' => JenisPtk::Guru,
            'status_kepegawaian_id' => fn (): int => StatusKepegawaian::query()->where('nama', 'PNS')->value('id')
                ?? StatusKepegawaian::factory()->create()->id,
            'nip' => null,
            'nuptk' => null,
            'gelar_depan' => null,
            'gelar_belakang' => null,
            'kode' => null,
            'sertifikasi' => false,
            'tahun_sertifikasi' => null,
            'status' => StatusPtk::Aktif,
            'tanggal_keluar' => null,
            'keterangan_keluar' => null,
            'log_penonaktifan_akun_id' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (Ptk $ptk) => $ptk->user->assignRole($ptk->jenis_ptk->peran()->value));
    }

    public function tendik(): static
    {
        return $this->state(['jenis_ptk' => JenisPtk::Tendik]);
    }
}
