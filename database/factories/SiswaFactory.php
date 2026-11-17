<?php

namespace Database\Factories;

use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Models\Siswa;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Rows that already follow SiswaService's rules: an active student on a
 * fresh active account whose username is the NIS and that holds the siswa
 * role (the role must exist, see PeranDanIzinSeeder).
 *
 * @extends Factory<Siswa>
 */
#[UseModel(Siswa::class)]
class SiswaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(fn (): array => ['jenis_kelamin' => fake()->randomElement(JenisKelamin::cases())]),
            'nis' => (string) fake()->unique()->numerify('2026######'),
            'nisn' => null,
            'tanggal_masuk' => null,
            'asal_sekolah' => null,
            'status' => StatusSiswa::Aktif,
            'tanggal_keluar' => null,
            'keterangan_keluar' => null,
            'log_penonaktifan_akun_id' => null,
        ];
    }

    /**
     * The username always follows the NIS, also when a test sets the NIS or
     * the account itself.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Siswa $siswa): void {
            $siswa->user->forceFill(['username' => $siswa->nis])->saveQuietly();
            $siswa->user->assignRole(Peran::Siswa->value);
        });
    }
}
