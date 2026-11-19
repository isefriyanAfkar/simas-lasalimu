<?php

namespace Database\Factories;

use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Models\WaliMurid;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Rows that already follow WaliMuridService's rules: a living parent on a
 * fresh INACTIVE account holding only the wali role (the role must exist, see
 * PeranDanIzinSeeder), username "wali" plus the five-digit account id.
 *
 * @extends Factory<WaliMurid>
 */
#[UseModel(WaliMurid::class)]
class WaliMuridFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['status_aktif' => false, 'jenis_kelamin' => null]),
            'pekerjaan' => null,
            'meninggal' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (WaliMurid $wali): void {
            $wali->user->forceFill(['username' => 'wali'.str_pad((string) $wali->user_id, 5, '0', STR_PAD_LEFT)])->saveQuietly();
            $wali->user->assignRole(Peran::Wali->value);
        });
    }

    public function meninggal(): static
    {
        return $this->state(['meninggal' => true]);
    }
}
