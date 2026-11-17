<?php

namespace Tests\Feature\Domains\People\Services;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Models\StatusKepegawaian;
use App\Domains\People\Services\StatusKepegawaianService;
use Closure;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The migration already holds PNS, PPPK, GTT, PTT and GTBPNS.
 */
class StatusKepegawaianServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
    }

    public function test_creates_an_active_status_with_normalized_name(): void
    {
        $status = $this->service()->create(['nama' => "\u{00A0} Honorer  \t Daerah ", 'urutan' => 6]);

        $status = $status->fresh();
        $this->assertSame('Honorer Daerah', $status->nama);
        $this->assertSame(6, $status->urutan);
        $this->assertTrue($status->status_aktif);
    }

    public function test_urutan_may_be_left_empty_and_accepts_the_bounds(): void
    {
        $kosong = $this->service()->create(['nama' => 'Honorer', 'urutan' => '']);
        $nol = $this->service()->create(['nama' => 'Kontrak', 'urutan' => 0]);
        $maks = $this->service()->create(['nama' => 'Magang', 'urutan' => 65535]);

        $this->assertNull($kosong->fresh()->urutan);
        $this->assertSame(0, $nol->fresh()->urutan);
        $this->assertSame(65535, $maks->fresh()->urutan);
    }

    public function test_create_ignores_a_status_in_the_input(): void
    {
        $status = $this->service()->create(['nama' => 'Honorer', 'status_aktif' => false]);

        $this->assertTrue($status->fresh()->status_aktif);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidData(): array
    {
        return [
            'nama kosong' => [['nama' => '  '], 'nama'],
            'nama tidak dikirim' => [['nama' => null], 'nama'],
            'nama lebih dari 50' => [['nama' => str_repeat('a', 51)], 'nama'],
            'nama kembar beda huruf' => [['nama' => ' pns '], 'nama'],
            'nama kembar diapit spasi tak terputus' => [['nama' => "\u{00A0}PPPK\u{00A0}"], 'nama'],
            'urutan bukan bilangan bulat' => [['urutan' => '1.5'], 'urutan'],
            'urutan negatif' => [['urutan' => -1], 'urutan'],
            'urutan lebih dari 65535' => [['urutan' => 65536], 'urutan'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidData')]
    public function test_create_rejects_invalid_data(array $override, string $field): void
    {
        $this->assertValidationError($field, fn () => $this->service()->create(['nama' => 'Honorer', 'urutan' => null, ...$override]));

        $this->assertSame(5, StatusKepegawaian::query()->count());
    }

    public function test_a_name_of_exactly_50_characters_is_accepted(): void
    {
        $status = $this->service()->create(['nama' => str_repeat('a', 50)]);

        $this->assertSame(50, mb_strlen($status->fresh()->nama));
    }

    public function test_a_taken_name_message_names_the_active_status_holding_it(): void
    {
        $this->assertValidationMessage(
            'nama',
            'Status kepegawaian PNS sudah ada.',
            fn () => $this->service()->create(['nama' => 'pns']),
        );
    }

    public function test_a_taken_name_message_says_the_status_holding_it_is_inactive(): void
    {
        $gtt = StatusKepegawaian::query()->where('nama', 'GTT')->sole();
        $this->service()->deactivate(User::factory()->superAdmin()->create(), $gtt);

        $this->assertValidationMessage(
            'nama',
            'Status kepegawaian GTT sudah ada, tetapi sedang tidak aktif. Aktifkan lagi status itu bila ingin memakainya.',
            fn () => $this->service()->create(['nama' => 'gtt']),
        );
    }

    public function test_update_may_keep_its_own_name_and_rejects_the_name_of_another(): void
    {
        $pns = StatusKepegawaian::query()->where('nama', 'PNS')->sole();

        $this->service()->update($pns, ['nama' => 'pns', 'urutan' => 9]);

        $this->assertSame(['pns', 9], array_values($pns->fresh()->only(['nama', 'urutan'])));
        $this->assertValidationError('nama', fn () => $this->service()->update($pns, ['nama' => 'PPPK']));
        $this->assertSame('pns', $pns->fresh()->nama);
    }

    public function test_update_does_not_change_the_status(): void
    {
        $status = StatusKepegawaian::factory()->nonaktif()->create();

        $this->service()->update($status, ['nama' => 'Nama Baru', 'status_aktif' => true]);

        $this->assertFalse($status->fresh()->status_aktif);
    }

    public function test_a_concurrent_create_with_the_same_name_ends_as_a_field_error(): void
    {
        StatusKepegawaian::creating(function (StatusKepegawaian $status): void {
            DB::table('status_kepegawaian')->insert(['nama' => $status->nama, 'status_aktif' => true]);
        });

        $this->assertValidationError('nama', fn () => $this->service()->create(['nama' => 'Honorer']));

        $this->assertSame(0, StatusKepegawaian::query()->where('nama', 'Honorer')->count());
    }

    public function test_a_concurrent_update_with_the_same_name_ends_as_a_field_error(): void
    {
        $pns = StatusKepegawaian::query()->where('nama', 'PNS')->sole();
        StatusKepegawaian::updating(function (): void {
            DB::table('status_kepegawaian')->insert(['nama' => 'Honorer', 'status_aktif' => true]);
        });

        $this->assertValidationError('nama', fn () => $this->service()->update($pns, ['nama' => 'Honorer']));

        $this->assertSame('PNS', $pns->fresh()->nama);
    }

    public function test_deactivating_and_activating_change_the_status_and_are_logged_with_the_actor(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $gtt = StatusKepegawaian::query()->where('nama', 'GTT')->sole();

        $this->service()->deactivate($superAdmin, $gtt);

        $this->assertFalse($gtt->fresh()->status_aktif);
        $log = Activity::query()->where('event', 'status_kepegawaian_dinonaktifkan')->sole();
        $this->assertSame('kepegawaian', $log->log_name);
        $this->assertSame('Status kepegawaian dinonaktifkan', $log->description);
        $this->assertTrue($log->causer->is($superAdmin));
        $this->assertTrue($log->subject->is($gtt));
        $this->assertSame(['status_aktif' => false], $log->attribute_changes['attributes']);
        $this->assertSame(['status_aktif' => true], $log->attribute_changes['old']);

        $this->service()->activate($superAdmin, $gtt);

        $this->assertTrue($gtt->fresh()->status_aktif);
        $log = Activity::query()->where('event', 'status_kepegawaian_diaktifkan')->sole();
        $this->assertSame('Status kepegawaian diaktifkan', $log->description);
        $this->assertTrue($log->causer->is($superAdmin));
        $this->assertSame(['status_aktif' => true], $log->attribute_changes['attributes']);
        $this->assertSame(['status_aktif' => false], $log->attribute_changes['old']);
    }

    public function test_a_status_change_that_changes_nothing_is_not_logged(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $aktif = StatusKepegawaian::factory()->create();
        $nonaktif = StatusKepegawaian::factory()->nonaktif()->create();

        $this->service()->activate($superAdmin, $aktif);
        $this->service()->deactivate($superAdmin, $nonaktif);

        $this->assertTrue($aktif->fresh()->status_aktif);
        $this->assertFalse($nonaktif->fresh()->status_aktif);
        $this->assertSame(0, Activity::query()->whereIn('event', ['status_kepegawaian_diaktifkan', 'status_kepegawaian_dinonaktifkan'])->count());
    }

    public function test_create_and_update_are_logged_by_the_trait_without_the_status(): void
    {
        $status = $this->service()->create(['nama' => 'Honorer']);
        $this->service()->update($status, ['nama' => 'Honorer Daerah', 'urutan' => 7]);

        $created = Activity::query()->where('event', 'created')->where('subject_type', $status->getMorphClass())->sole();
        $this->assertSame('kepegawaian', $created->log_name);
        $this->assertSame('Status kepegawaian dibuat', $created->description);
        $attributes = $created->attribute_changes['attributes'];
        ksort($attributes);
        $this->assertSame(['nama' => 'Honorer', 'urutan' => null], $attributes);

        $updated = Activity::query()->where('event', 'updated')->where('subject_type', $status->getMorphClass())->sole();
        $attributes = $updated->attribute_changes['attributes'];
        ksort($attributes);
        $this->assertSame(['nama' => 'Honorer Daerah', 'urutan' => 7], $attributes);
    }

    public function test_display_order_is_by_urutan_with_empty_last_then_by_name(): void
    {
        StatusKepegawaian::query()->delete();
        StatusKepegawaian::factory()->create(['nama' => 'Zeta', 'urutan' => null]);
        StatusKepegawaian::factory()->create(['nama' => 'Alfa', 'urutan' => null]);
        StatusKepegawaian::factory()->create(['nama' => 'Beta', 'urutan' => 2]);
        StatusKepegawaian::factory()->create(['nama' => 'Gama', 'urutan' => 1]);
        StatusKepegawaian::factory()->create(['nama' => 'Abjad', 'urutan' => 2]);

        $this->assertSame(
            ['Gama', 'Abjad', 'Beta', 'Alfa', 'Zeta'],
            StatusKepegawaian::query()->urutanTampilan()->pluck('nama')->all(),
        );
    }

    private function service(): StatusKepegawaianService
    {
        return app(StatusKepegawaianService::class);
    }

    private function assertValidationError(string $field, Closure $operation): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors(), 'Errors: '.json_encode($exception->errors()));

            return;
        }

        $this->fail("Expected a validation error on [{$field}].");
    }

    private function assertValidationMessage(string $field, string $message, Closure $operation): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertSame([$message], $exception->errors()[$field] ?? null, 'Errors: '.json_encode($exception->errors()));

            return;
        }

        $this->fail("Expected a validation error on [{$field}].");
    }
}
