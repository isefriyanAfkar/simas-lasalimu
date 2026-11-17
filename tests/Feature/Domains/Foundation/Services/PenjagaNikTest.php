<?php

namespace Tests\Feature\Domains\Foundation\Services;

use App\Domains\Foundation\Services\KunciNikTidakSiap;
use App\Domains\Foundation\Services\PenjagaNik;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PenjagaNikTest extends TestCase
{
    public function test_hash_is_hmac_sha256_with_the_nik_key_from_config(): void
    {
        $this->assertSame(
            '883da60451726b178618782288e5205fb264f48026ba264c8aef7adfe48b5bed',
            app(PenjagaNik::class)->hash('7404012345670001'),
        );
    }

    public function test_hash_does_not_use_app_key(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);

        $this->assertSame(
            '883da60451726b178618782288e5205fb264f48026ba264c8aef7adfe48b5bed',
            app(PenjagaNik::class)->hash('7404012345670001'),
        );
    }

    public function test_hash_accepts_a_raw_key_without_base64_prefix(): void
    {
        config(['simas.nik_hmac_key' => 'kunci-uji-nik-simas-32-byte-abcd']);

        $this->assertSame(
            '883da60451726b178618782288e5205fb264f48026ba264c8aef7adfe48b5bed',
            app(PenjagaNik::class)->hash('7404012345670001'),
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function kunciTidakSiap(): array
    {
        return [
            'kosong' => [null],
            'string kosong' => [''],
            'terlalu pendek' => ['base64:'.base64_encode(str_repeat('k', 31))],
            'base64 rusak' => ['base64:!!!'],
        ];
    }

    #[DataProvider('kunciTidakSiap')]
    public function test_refuses_to_hash_without_a_usable_key(mixed $kunci): void
    {
        config(['simas.nik_hmac_key' => $kunci]);

        $this->expectException(KunciNikTidakSiap::class);

        app(PenjagaNik::class)->hash('7404012345670001');
    }

    public function test_masks_all_but_the_first_and_last_four_digits(): void
    {
        $this->assertSame('7404********0001', PenjagaNik::samarkan('7404012345670001'));
    }
}
