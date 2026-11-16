<?php

namespace Tests\Feature\Domains\Academic\Services;

use App\Domains\Academic\Models\MataPelajaran;
use App\Domains\Academic\Services\MataPelajaranService;
use App\Domains\Foundation\Models\User;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class MataPelajaranServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
    }

    public function test_creates_an_active_subject_with_uppercase_trimmed_code_and_trimmed_name(): void
    {
        $mataPelajaran = $this->service()->create(['kode' => ' mtk ', 'nama' => '  Matematika ', 'urutan' => 3]);

        $mataPelajaran = $mataPelajaran->fresh();
        $this->assertSame('MTK', $mataPelajaran->kode);
        $this->assertSame('Matematika', $mataPelajaran->nama);
        $this->assertSame(3, $mataPelajaran->urutan);
        $this->assertTrue($mataPelajaran->status_aktif);
    }

    public function test_urutan_may_be_left_empty(): void
    {
        $mataPelajaran = $this->service()->create(['kode' => 'BIND', 'nama' => 'Bahasa Indonesia', 'urutan' => null]);

        $this->assertNull($mataPelajaran->fresh()->urutan);
    }

    public function test_create_ignores_a_status_in_the_input(): void
    {
        $mataPelajaran = $this->service()->create(['kode' => 'BIND', 'nama' => 'Bahasa Indonesia', 'status_aktif' => false]);

        $this->assertTrue($mataPelajaran->fresh()->status_aktif);
    }

    /**
     * Existing subject: MTK / Matematika.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidData(): array
    {
        return [
            'kode kosong' => [['kode' => '  '], 'kode'],
            'kode 1 karakter' => [['kode' => 'A'], 'kode'],
            'kode 11 karakter' => [['kode' => 'ABCDEFGHIJK'], 'kode'],
            'kode berspasi di tengah' => [['kode' => 'B IND'], 'kode'],
            'kode bertanda hubung' => [['kode' => 'B-ING'], 'kode'],
            'kode kembar beda huruf' => [['kode' => 'mtk'], 'kode'],
            'nama kosong' => [['nama' => ''], 'nama'],
            'nama lebih dari 100' => [['nama' => str_repeat('a', 101)], 'nama'],
            'nama kembar beda huruf' => [['nama' => ' MATEMATIKA '], 'nama'],
            'urutan bukan bilangan bulat' => [['urutan' => '1.5'], 'urutan'],
            'urutan negatif' => [['urutan' => -1], 'urutan'],
            'urutan lebih dari 65535' => [['urutan' => 65536], 'urutan'],
            'kode berhuruf non-ASCII' => [['kode' => 'MTKÉ'], 'kode'],
            'nama kembar diapit spasi tak terputus' => [['nama' => "\u{00A0}Matematika\u{00A0}"], 'nama'],
        ];
    }

    public function test_urutan_accepts_the_largest_value(): void
    {
        $mataPelajaran = $this->service()->create(['kode' => 'MTK', 'nama' => 'Matematika', 'urutan' => 65535]);

        $this->assertSame(65535, $mataPelajaran->fresh()->urutan);
    }

    public function test_name_whitespace_is_normalized_so_near_duplicates_are_caught(): void
    {
        $this->service()->create(['kode' => 'BIND', 'nama' => "\u{00A0} Bahasa  \t Indonesia\u{00A0}"]);

        $this->assertSame('Bahasa Indonesia', MataPelajaran::query()->sole()->nama);
        $this->assertValidationError('nama', fn () => $this->service()->create(['kode' => 'BIND2', 'nama' => "Bahasa\u{00A0}Indonesia"]));
    }

    public function test_a_taken_code_message_names_the_inactive_subject_holding_it(): void
    {
        MataPelajaran::factory()->nonaktif()->create(['kode' => 'MTK', 'nama' => 'Matematika']);

        try {
            $this->service()->create(['kode' => 'mtk', 'nama' => 'Matematika Baru']);
            $this->fail('A taken code was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Kode MTK sudah dipakai oleh mata pelajaran Matematika yang sedang tidak aktif. Aktifkan lagi mata pelajaran itu bila ingin memakainya.'],
                $exception->errors()['kode'],
            );
        }
    }

    public function test_a_taken_name_message_names_the_active_subject_holding_it(): void
    {
        MataPelajaran::factory()->create(['kode' => 'MTK', 'nama' => 'Matematika']);

        try {
            $this->service()->create(['kode' => 'MTK2', 'nama' => 'matematika']);
            $this->fail('A taken name was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Nama ini sudah dipakai oleh mata pelajaran MTK.'], $exception->errors()['nama']);
        }
    }

    /**
     * Simulates another operator saving the same value between this save's
     * validation and its write: the unique index must end as a field error.
     *
     * @return array<string, array{string}>
     */
    public static function uniqueFields(): array
    {
        return [
            'kode' => ['kode'],
            'nama' => ['nama'],
        ];
    }

    #[DataProvider('uniqueFields')]
    public function test_a_concurrent_create_with_the_same_value_ends_as_a_field_error(string $field): void
    {
        MataPelajaran::creating(function (MataPelajaran $mataPelajaran) use ($field): void {
            DB::table('mata_pelajaran')->insert([
                'kode' => $field === 'kode' ? $mataPelajaran->kode : 'LAIN',
                'nama' => $field === 'nama' ? $mataPelajaran->nama : 'Lain',
                'status_aktif' => true,
            ]);
        });

        $this->assertValidationError($field, fn () => $this->service()->create(['kode' => 'MTK', 'nama' => 'Matematika']));

        $this->assertSame(0, MataPelajaran::query()->count());
    }

    public function test_a_concurrent_update_with_the_same_code_ends_as_a_field_error(): void
    {
        $mataPelajaran = $this->service()->create(['kode' => 'MTK', 'nama' => 'Matematika']);
        MataPelajaran::updating(function (): void {
            DB::table('mata_pelajaran')->insert(['kode' => 'MTKW', 'nama' => 'Lain', 'status_aktif' => true]);
        });

        $this->assertValidationError('kode', fn () => $this->service()->update($mataPelajaran, ['kode' => 'MTKW', 'nama' => 'Matematika']));

        $this->assertSame('MTK', $mataPelajaran->fresh()->kode);
    }

    public function test_deactivating_writes_no_trait_update_entry(): void
    {
        $mataPelajaran = $this->service()->create(['kode' => 'MTK', 'nama' => 'Matematika']);

        $this->service()->deactivate(User::factory()->operator()->create(), $mataPelajaran);

        $this->assertSame(0, Activity::query()->where('event', 'updated')->where('subject_type', $mataPelajaran->getMorphClass())->count());
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidData')]
    public function test_create_rejects_invalid_data(array $override, string $field): void
    {
        $this->service()->create(['kode' => 'MTK', 'nama' => 'Matematika']);

        $this->assertValidationError($field, fn () => $this->service()->create([
            'kode' => 'BIND',
            'nama' => 'Bahasa Indonesia',
            'urutan' => null,
            ...$override,
        ]));

        $this->assertSame(1, MataPelajaran::query()->count());
    }

    public function test_accepts_codes_of_2_and_10_characters(): void
    {
        $this->service()->create(['kode' => 'PA', 'nama' => 'Pendidikan Agama']);
        $this->service()->create(['kode' => 'ABCDE12345', 'nama' => 'Muatan Lokal']);

        $this->assertSame(['ABCDE12345', 'PA'], MataPelajaran::query()->orderBy('kode')->pluck('kode')->all());
    }

    public function test_update_may_keep_its_own_code_and_name(): void
    {
        $mataPelajaran = $this->service()->create(['kode' => 'MTK', 'nama' => 'Matematika']);

        $this->service()->update($mataPelajaran, ['kode' => 'mtk', 'nama' => 'Matematika', 'urutan' => 2]);

        $this->assertSame(2, $mataPelajaran->fresh()->urutan);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function takenByAnother(): array
    {
        return [
            'kode milik yang lain' => [['kode' => 'mtk'], 'kode'],
            'nama milik yang lain' => [['nama' => 'matematika'], 'nama'],
        ];
    }

    /**
     * @param  array<string, string>  $override
     */
    #[DataProvider('takenByAnother')]
    public function test_update_rejects_a_code_or_name_of_another_subject(array $override, string $field): void
    {
        $this->service()->create(['kode' => 'MTK', 'nama' => 'Matematika']);
        $bind = $this->service()->create(['kode' => 'BIND', 'nama' => 'Bahasa Indonesia']);

        $this->assertValidationError($field, fn () => $this->service()->update($bind, [
            'kode' => 'BIND',
            'nama' => 'Bahasa Indonesia',
            ...$override,
        ]));

        $this->assertSame(['BIND', 'Bahasa Indonesia'], array_values($bind->fresh()->only(['kode', 'nama'])));
    }

    public function test_update_does_not_change_the_status(): void
    {
        $mataPelajaran = MataPelajaran::factory()->nonaktif()->create();

        $this->service()->update($mataPelajaran, ['kode' => $mataPelajaran->kode, 'nama' => 'Nama Baru', 'status_aktif' => true]);

        $this->assertFalse($mataPelajaran->fresh()->status_aktif);
    }

    public function test_deactivating_and_activating_change_the_status_and_are_logged_with_the_actor(): void
    {
        $operator = User::factory()->operator()->create();
        $mataPelajaran = $this->service()->create(['kode' => 'MTK', 'nama' => 'Matematika']);

        $this->service()->deactivate($operator, $mataPelajaran);

        $this->assertFalse($mataPelajaran->fresh()->status_aktif);
        $log = Activity::query()->where('event', 'mata_pelajaran_dinonaktifkan')->sole();
        $this->assertSame('akademik', $log->log_name);
        $this->assertTrue($log->causer->is($operator));
        $this->assertTrue($log->subject->is($mataPelajaran));
        $this->assertSame(['status_aktif' => false], $log->attribute_changes['attributes']);
        $this->assertSame(['status_aktif' => true], $log->attribute_changes['old']);

        $this->service()->activate($operator, $mataPelajaran);

        $this->assertTrue($mataPelajaran->fresh()->status_aktif);
        $log = Activity::query()->where('event', 'mata_pelajaran_diaktifkan')->sole();
        $this->assertTrue($log->causer->is($operator));
        $this->assertSame(['status_aktif' => true], $log->attribute_changes['attributes']);
        $this->assertSame(['status_aktif' => false], $log->attribute_changes['old']);
    }

    public function test_a_status_change_that_changes_nothing_is_not_logged(): void
    {
        $operator = User::factory()->operator()->create();
        $aktif = MataPelajaran::factory()->create();
        $nonaktif = MataPelajaran::factory()->nonaktif()->create();

        $this->service()->activate($operator, $aktif);
        $this->service()->deactivate($operator, $nonaktif);

        $this->assertTrue($aktif->fresh()->status_aktif);
        $this->assertFalse($nonaktif->fresh()->status_aktif);
        $this->assertSame(0, Activity::query()->whereIn('event', ['mata_pelajaran_diaktifkan', 'mata_pelajaran_dinonaktifkan'])->count());
    }

    public function test_create_and_update_are_logged_by_the_trait_without_the_status(): void
    {
        $mataPelajaran = $this->service()->create(['kode' => 'MTK', 'nama' => 'Matematika']);
        $this->service()->update($mataPelajaran, ['kode' => 'MTK', 'nama' => 'Matematika Wajib', 'urutan' => 1]);

        $created = Activity::query()->where('event', 'created')->where('subject_type', $mataPelajaran->getMorphClass())->sole();
        $attributes = $created->attribute_changes['attributes'];
        ksort($attributes);
        $this->assertSame(['kode' => 'MTK', 'nama' => 'Matematika', 'urutan' => null], $attributes);

        $updated = Activity::query()->where('event', 'updated')->where('subject_type', $mataPelajaran->getMorphClass())->sole();
        $attributes = $updated->attribute_changes['attributes'];
        ksort($attributes);
        $this->assertSame(['nama' => 'Matematika Wajib', 'urutan' => 1], $attributes);
    }

    public function test_display_order_is_by_urutan_with_empty_last_then_by_name(): void
    {
        MataPelajaran::factory()->create(['kode' => 'SEJ', 'nama' => 'Sejarah', 'urutan' => null]);
        MataPelajaran::factory()->create(['kode' => 'BIOL', 'nama' => 'Biologi', 'urutan' => null]);
        MataPelajaran::factory()->create(['kode' => 'MTK', 'nama' => 'Matematika', 'urutan' => 2]);
        MataPelajaran::factory()->create(['kode' => 'PAI', 'nama' => 'Pendidikan Agama', 'urutan' => 1]);
        MataPelajaran::factory()->create(['kode' => 'BIND', 'nama' => 'Bahasa Indonesia', 'urutan' => 2]);

        $this->assertSame(
            ['PAI', 'BIND', 'MTK', 'BIOL', 'SEJ'],
            MataPelajaran::query()->urutanTampilan()->pluck('kode')->all(),
        );
    }

    private function service(): MataPelajaranService
    {
        return app(MataPelajaranService::class);
    }

    private function assertValidationError(string $field, callable $operation): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors(), 'Errors: '.json_encode($exception->errors()));

            return;
        }

        $this->fail("Expected a validation error on [{$field}].");
    }
}
