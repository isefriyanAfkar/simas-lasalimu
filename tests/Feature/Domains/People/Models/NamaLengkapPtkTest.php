<?php

namespace Tests\Feature\Domains\People\Models;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Models\Ptk;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The one way a staff member's full name with titles is built.
 */
class NamaLengkapPtkTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array<string, array{string|null, string, string|null, string}>
     */
    public static function names(): array
    {
        return [
            'gelar depan dan belakang' => ['Dra.', 'Hafida', 'S.Pd., Gr.', 'Dra. Hafida, S.Pd., Gr.'],
            'hanya gelar depan' => ['Drs.', 'Ahmad', null, 'Drs. Ahmad'],
            'hanya gelar belakang' => [null, 'Siti Aminah', 'S.Pd.', 'Siti Aminah, S.Pd.'],
            'tanpa gelar' => [null, 'Budi Santoso', null, 'Budi Santoso'],
            'gelar kosong dianggap tidak ada' => ['', 'Budi Santoso', '  ', 'Budi Santoso'],
            'spasi di tepi dibuang' => [' Dr. ', ' Hafida ', ' M.Pd. ', 'Dr. Hafida, M.Pd.'],
        ];
    }

    #[DataProvider('names')]
    public function test_builds_the_full_name_with_titles(?string $gelarDepan, string $nama, ?string $gelarBelakang, string $expected): void
    {
        $this->assertSame($expected, Ptk::namaLengkapDari($gelarDepan, $nama, $gelarBelakang));
    }

    public function test_the_model_attribute_uses_the_account_name(): void
    {
        $this->seed(PeranDanIzinSeeder::class);
        $ptk = Ptk::factory()
            ->for(User::factory()->state(['name' => 'Hafida']))
            ->create(['gelar_depan' => 'Dra.', 'gelar_belakang' => 'S.Pd., Gr.']);

        $this->assertSame('Dra. Hafida, S.Pd., Gr.', $ptk->fresh()->nama_lengkap);
    }
}
