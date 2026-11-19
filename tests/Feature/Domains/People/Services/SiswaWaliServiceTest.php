<?php

namespace Tests\Feature\Domains\People\Services;

use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\HubunganWali;
use App\Domains\People\Models\Ptk;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Models\SiswaWali;
use App\Domains\People\Models\WaliMurid;
use App\Domains\People\Services\PelanggaranAturanKesiswaan;
use App\Domains\People\Services\SiswaWaliService;
use Closure;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * A student's parents and guardians: adding a new or an existing one,
 * changing the relation, the main contact, and letting go. Per student: each
 * guardian once, at most one ayah and one ibu, at most one utama.
 */
class SiswaWaliServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string NIK = '7404012345670003';

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        $this->operator = User::factory()->operator()->create();
    }

    public function test_a_new_parent_gets_an_inactive_parent_account_and_becomes_the_main_contact(): void
    {
        $budi = $this->siswa('Budi');

        $siswaWali = $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => '  Ahmad ', 'no_hp' => '0812-3456-7890', 'pekerjaan' => ' Petani ']));

        $wali = $siswaWali->waliMurid->fresh();
        $user = $wali->user;
        $this->assertSame('Ahmad', $user->name);
        $this->assertFalse($user->status_aktif);
        $this->assertSame(['wali'], $user->getRoleNames()->all());
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
        $this->assertSame('wali'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT), $user->username);
        $this->assertSame('6281234567890', $user->no_hp);
        $this->assertSame('Petani', $wali->pekerjaan);
        $this->assertFalse($wali->meninggal);
        $this->assertFalse(password_verify('', $user->getAuthPassword()));
        $this->assertFalse(password_verify($user->username, $user->getAuthPassword()));

        $this->assertSame(HubunganWali::Ayah, $siswaWali->hubungan);
        $this->assertTrue($siswaWali->utama);
    }

    public function test_only_the_name_is_required(): void
    {
        $siswaWali = $this->service()->tambahkan($this->operator, $this->siswa('Budi'), [
            'wali' => 'baru',
            'hubungan' => 'wali',
            'name' => 'Pak Lurah',
        ]);

        $user = $siswaWali->waliMurid->user->fresh();
        $this->assertNull($user->jenis_kelamin);
        $this->assertNull($user->no_hp);

        $this->assertValidationError('name', fn () => $this->service()->tambahkan($this->operator, $this->siswa('Ani'), ['wali' => 'baru', 'hubungan' => 'wali', 'name' => ' ']));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidNewParents(): array
    {
        return [
            'hubungan kosong' => [['hubungan' => null], 'hubungan'],
            'hubungan tidak dikenal' => [['hubungan' => 'kakek'], 'hubungan'],
            'mode kosong' => [['wali' => null], 'wali'],
            'NIK 15 digit' => [['nik' => '740401234567000'], 'nik'],
            'nomor HP tidak sah' => [['no_hp' => '12345'], 'no_hp'],
            'pekerjaan lebih dari 100' => [['pekerjaan' => str_repeat('a', 101)], 'pekerjaan'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidNewParents')]
    public function test_a_new_parent_follows_the_biodata_rules(array $override, string $field): void
    {
        $this->assertValidationError($field, fn () => $this->service()->tambahkan($this->operator, $this->siswa('Budi'), $this->baru($override)));

        $this->assertSame(0, WaliMurid::query()->count());
        $this->assertSame(0, SiswaWali::query()->count());
    }

    public function test_father_and_mother_may_share_one_phone_number(): void
    {
        $budi = $this->siswa('Budi');

        $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Ahmad', 'no_hp' => '081234567890']));
        $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Siti', 'hubungan' => 'ibu', 'no_hp' => '+6281234567890']));

        $this->assertSame(['6281234567890', '6281234567890'], User::query()->role('wali')->orderBy('id')->pluck('no_hp')->all());
    }

    public function test_one_parent_for_two_siblings(): void
    {
        $budi = $this->siswa('Budi');
        $ani = $this->siswa('Ani');
        $ahmad = $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Ahmad']))->waliMurid;

        $keAni = $this->service()->tambahkan($this->operator, $ani, ['wali' => 'ada', 'wali_murid_id' => $ahmad->id, 'hubungan' => 'ayah']);

        $this->assertTrue($keAni->waliMurid->is($ahmad));
        $this->assertTrue($keAni->utama);
        $this->assertSame(1, WaliMurid::query()->count());
        $this->assertEqualsCanonicalizing([$budi->id, $ani->id], $ahmad->siswaWali()->pluck('siswa_id')->all());
    }

    public function test_the_same_parent_twice_for_one_student_is_rejected(): void
    {
        $budi = $this->siswa('Budi');
        $ahmad = $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Ahmad', 'hubungan' => 'wali']))->waliMurid;

        try {
            $this->service()->tambahkan($this->operator, $budi, ['wali' => 'ada', 'wali_murid_id' => $ahmad->id, 'hubungan' => 'ayah']);
            $this->fail('A parent was added twice.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Ahmad sudah menjadi wali murid Budi.'], $exception->errors()['wali_murid_id']);
        }

        $this->assertSame(1, SiswaWali::query()->count());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function singleRelations(): array
    {
        return [
            'ayah' => ['ayah', 'laki-laki', 'Budi sudah punya ayah: Ahmad.'],
            'ibu' => ['ibu', 'perempuan', 'Budi sudah punya ibu: Ahmad.'],
        ];
    }

    #[DataProvider('singleRelations')]
    public function test_a_student_has_at_most_one_father_and_one_mother(string $hubungan, string $jenisKelamin, string $pesan): void
    {
        $budi = $this->siswa('Budi');
        $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Ahmad', 'hubungan' => $hubungan, 'jenis_kelamin' => $jenisKelamin]));

        try {
            $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Kedua', 'hubungan' => $hubungan, 'jenis_kelamin' => $jenisKelamin]));
            $this->fail('A second '.$hubungan.' was added.');
        } catch (ValidationException $exception) {
            $this->assertSame([$pesan], $exception->errors()['hubungan']);
        }

        $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Paman', 'hubungan' => 'wali', 'jenis_kelamin' => null]));
        $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Bibi', 'hubungan' => 'wali', 'jenis_kelamin' => null]));
        $this->assertSame(3, SiswaWali::query()->count());
    }

    public function test_the_database_refuses_a_second_father_and_a_second_main_contact(): void
    {
        $budi = $this->siswa('Budi');
        SiswaWali::factory()->for($budi)->create(['hubungan' => HubunganWali::Ayah, 'utama' => true]);

        foreach ([['hubungan' => HubunganWali::Ayah, 'utama' => false], ['hubungan' => HubunganWali::Wali, 'utama' => true]] as $baris) {
            try {
                SiswaWali::factory()->for($budi)->create($baris);
                $this->fail('The database accepted '.json_encode($baris));
            } catch (QueryException) {
                // Refused by the unique index.
            }
        }

        $this->assertSame(1, SiswaWali::query()->count());
    }

    public function test_only_the_first_parent_becomes_the_main_contact(): void
    {
        $budi = $this->siswa('Budi');

        $pertama = $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Ahmad']));
        $kedua = $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Siti', 'hubungan' => 'ibu']));

        $this->assertTrue($pertama->fresh()->utama);
        $this->assertFalse($kedua->fresh()->utama);
    }

    public function test_a_concurrent_save_never_leaves_two_main_contacts(): void
    {
        $budi = $this->siswa('Budi');
        $lain = WaliMurid::factory()->create();

        SiswaWali::creating(function (SiswaWali $siswaWali) use ($lain): void {
            if ($siswaWali->utama) {
                DB::table('siswa_wali')->insert(['siswa_id' => $siswaWali->siswa_id, 'wali_murid_id' => $lain->id, 'hubungan' => 'wali', 'utama' => true]);
            }
        });

        try {
            $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Ahmad']));
            $this->fail('Two main contacts were saved.');
        } catch (ValidationException $exception) {
            $this->assertSame([SiswaWaliService::PESAN_UTAMA_BERUBAH], $exception->errors()['wali_murid_id']);
        }

        $this->assertSame(0, SiswaWali::query()->count());
        $this->assertDatabaseMissing('users', ['name' => 'Ahmad']);
    }

    public function test_a_concurrent_change_of_the_main_contact_never_leaves_two(): void
    {
        $budi = $this->siswa('Budi');
        $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Ahmad']));
        $siti = $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Siti', 'hubungan' => 'ibu']));
        $lain = WaliMurid::factory()->create();

        SiswaWali::updating(function (SiswaWali $siswaWali) use ($lain): void {
            if ($siswaWali->utama && $siswaWali->isDirty('utama')) {
                DB::table('siswa_wali')->insert(['siswa_id' => $siswaWali->siswa_id, 'wali_murid_id' => $lain->id, 'hubungan' => 'wali', 'utama' => true]);
            }
        });

        $this->assertValidationError('wali_murid_id', fn () => $this->service()->jadikanUtama($this->operator, $siti));

        $this->assertSame(1, SiswaWali::query()->where('siswa_id', $budi->id)->where('utama', true)->count());
        $this->assertSame('Ahmad', SiswaWali::query()->where('utama', true)->sole()->waliMurid->user->name);
    }

    public function test_making_another_parent_the_main_contact_moves_it_and_is_logged(): void
    {
        $budi = $this->siswa('Budi');
        $ahmad = $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Ahmad']));
        $siti = $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Siti', 'hubungan' => 'ibu']));

        $this->service()->jadikanUtama($this->operator, $siti);

        $this->assertFalse($ahmad->fresh()->utama);
        $this->assertTrue($siti->fresh()->utama);

        $log = Activity::query()->where('event', 'wali_utama_diganti')->sole();
        $this->assertSame('kesiswaan', $log->log_name);
        $this->assertTrue($log->subject->is($budi));
        $this->assertSame(['wali_utama' => 'Siti'], $log->attribute_changes['attributes']);
        $this->assertSame(['wali_utama' => 'Ahmad'], $log->attribute_changes['old']);
    }

    public function test_the_main_contact_cannot_be_let_go_before_another_is_chosen(): void
    {
        $budi = $this->siswa('Budi');
        $ahmad = $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Ahmad']));

        $this->assertRejected('Ahmad adalah wali utama Budi. Pilih wali utama lain dulu.', fn () => $this->service()->lepas($this->operator, $ahmad));

        $siti = $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Siti', 'hubungan' => 'ibu']));
        $this->assertRejected('Ahmad adalah wali utama Budi. Pilih wali utama lain dulu.', fn () => $this->service()->lepas($this->operator, $ahmad->fresh()));

        $this->service()->jadikanUtama($this->operator, $siti);
        $this->service()->lepas($this->operator, $ahmad->fresh());

        $this->assertSame([$siti->id], SiswaWali::query()->pluck('id')->all());
        $log = Activity::query()->where('event', 'wali_dilepas')->sole();
        $this->assertTrue($log->subject->is($budi));
        $this->assertSame(['wali' => 'Ahmad', 'hubungan' => 'ayah'], $log->attribute_changes['old']);
        $this->assertNotNull(WaliMurid::query()->find($ahmad->wali_murid_id));
    }

    public function test_a_parent_who_died_cannot_become_the_main_contact(): void
    {
        $budi = $this->siswa('Budi');
        $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Ahmad']));
        $almarhumah = WaliMurid::factory()->meninggal()->for(User::factory()->state(['name' => 'Siti', 'jenis_kelamin' => 'perempuan']))->create();
        $siti = $this->service()->tambahkan($this->operator, $budi, ['wali' => 'ada', 'wali_murid_id' => $almarhumah->id, 'hubungan' => 'ibu']);

        $this->assertRejected('Siti ditandai meninggal dan tidak bisa dijadikan wali utama.', fn () => $this->service()->jadikanUtama($this->operator, $siti));
        $this->assertFalse($siti->fresh()->utama);
    }

    public function test_a_parent_who_died_does_not_become_the_main_contact_automatically(): void
    {
        $budi = $this->siswa('Budi');
        $almarhum = WaliMurid::factory()->meninggal()->for(User::factory()->state(['name' => 'Ahmad', 'jenis_kelamin' => 'laki-laki']))->create();

        $ahmad = $this->service()->tambahkan($this->operator, $budi, ['wali' => 'ada', 'wali_murid_id' => $almarhum->id, 'hubungan' => 'ayah']);
        $siti = $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Siti', 'hubungan' => 'ibu']));

        $this->assertFalse($ahmad->fresh()->utama);
        $this->assertTrue($siti->fresh()->utama);
    }

    public function test_the_gender_is_filled_in_from_the_relation(): void
    {
        $budi = $this->siswa('Budi');

        $ayah = $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Ahmad', 'hubungan' => 'ayah', 'jenis_kelamin' => null]));
        $ibu = $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Siti', 'hubungan' => 'ibu', 'jenis_kelamin' => null]));
        $wali = $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Paman', 'hubungan' => 'wali', 'jenis_kelamin' => null]));

        $this->assertSame(JenisKelamin::LakiLaki, $ayah->waliMurid->user->fresh()->jenis_kelamin);
        $this->assertSame(JenisKelamin::Perempuan, $ibu->waliMurid->user->fresh()->jenis_kelamin);
        $this->assertNull($wali->waliMurid->user->fresh()->jenis_kelamin);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function contradictions(): array
    {
        return [
            'ayah perempuan' => ['ayah', 'perempuan', 'Ayah harus laki-laki.'],
            'ibu laki-laki' => ['ibu', 'laki-laki', 'Ibu harus perempuan.'],
        ];
    }

    #[DataProvider('contradictions')]
    public function test_a_gender_that_contradicts_the_relation_is_rejected(string $hubungan, string $jenisKelamin, string $pesan): void
    {
        try {
            $this->service()->tambahkan($this->operator, $this->siswa('Budi'), $this->baru(['hubungan' => $hubungan, 'jenis_kelamin' => $jenisKelamin]));
            $this->fail('A contradicting gender was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame([$pesan], $exception->errors()['jenis_kelamin']);
        }

        $this->assertSame(0, WaliMurid::query()->count());
    }

    public function test_an_existing_parent_of_the_other_gender_cannot_become_father(): void
    {
        $siti = WaliMurid::factory()->for(User::factory()->state(['name' => 'Siti', 'jenis_kelamin' => 'perempuan']))->create();

        try {
            $this->service()->tambahkan($this->operator, $this->siswa('Budi'), ['wali' => 'ada', 'wali_murid_id' => $siti->id, 'hubungan' => 'ayah']);
            $this->fail('A woman became father.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Siti tercatat perempuan, tidak bisa menjadi ayah.'], $exception->errors()['hubungan']);
        }
    }

    public function test_an_existing_parent_without_gender_gets_it_from_the_relation(): void
    {
        $tanpa = WaliMurid::factory()->for(User::factory()->state(['name' => 'Ahmad', 'jenis_kelamin' => null]))->create();

        $this->service()->tambahkan($this->operator, $this->siswa('Budi'), ['wali' => 'ada', 'wali_murid_id' => $tanpa->id, 'hubungan' => 'ayah']);

        $this->assertSame(JenisKelamin::LakiLaki, $tanpa->user->fresh()->jenis_kelamin);
    }

    public function test_changing_the_relation_follows_the_same_rules_and_is_logged(): void
    {
        $budi = $this->siswa('Budi');
        $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Ahmad']));
        $paman = $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Paman', 'hubungan' => 'wali', 'jenis_kelamin' => 'laki-laki']));

        $this->assertValidationError('hubungan', fn () => $this->service()->ubahHubungan($this->operator, $paman, ['hubungan' => 'ayah']));
        $this->assertValidationError('hubungan', fn () => $this->service()->ubahHubungan($this->operator, $paman, ['hubungan' => 'ibu']));

        $tanpa = $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Bibi', 'hubungan' => 'wali', 'jenis_kelamin' => null]));
        $this->service()->ubahHubungan($this->operator, $tanpa, ['hubungan' => 'ibu']);

        $this->assertSame(HubunganWali::Ibu, $tanpa->fresh()->hubungan);
        $this->assertSame(JenisKelamin::Perempuan, $tanpa->waliMurid->user->fresh()->jenis_kelamin);
        $log = Activity::query()->where('event', 'hubungan_wali_diubah')->sole();
        $this->assertSame(['wali' => 'Bibi', 'hubungan' => 'ibu'], $log->attribute_changes['attributes']);
        $this->assertSame(['wali' => 'Bibi', 'hubungan' => 'wali'], $log->attribute_changes['old']);
    }

    public function test_the_nik_of_a_staff_member_names_them_without_the_nik(): void
    {
        $guru = User::factory()->create(['name' => 'Hafida']);
        $guru->isiNik(self::NIK);
        $guru->save();
        Ptk::factory()->for($guru)->create(['gelar_depan' => 'Dra.']);

        try {
            $this->service()->tambahkan($this->operator, $this->siswa('Budi'), $this->baru(['nik' => self::NIK]));
            $this->fail('A staff NIK was taken.');
        } catch (ValidationException $exception) {
            $this->assertSame(['NIK ini milik guru/staf Dra. Hafida. Kosongkan NIK untuk data wali ini.'], $exception->errors()['nik']);
            $this->assertStringNotContainsString(self::NIK, json_encode($exception->errors()));
        }
    }

    public function test_the_nik_of_another_parent_suggests_choosing_that_parent(): void
    {
        $this->service()->tambahkan($this->operator, $this->siswa('Budi'), $this->baru(['name' => 'Ahmad', 'nik' => self::NIK]));

        try {
            $this->service()->tambahkan($this->operator, $this->siswa('Ani'), $this->baru(['name' => 'Ahmad Lagi', 'nik' => self::NIK]));
            $this->fail('A duplicate parent NIK was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['NIK ini milik wali Ahmad. Pilih wali yang sudah ada.'], $exception->errors()['nik']);
        }

        $this->assertSame(1, WaliMurid::query()->count());
    }

    public function test_an_unknown_existing_parent_is_rejected(): void
    {
        $this->assertValidationError('wali_murid_id', fn () => $this->service()->tambahkan($this->operator, $this->siswa('Budi'), ['wali' => 'ada', 'wali_murid_id' => 999999, 'hubungan' => 'wali']));
        $this->assertValidationError('wali_murid_id', fn () => $this->service()->tambahkan($this->operator, $this->siswa('Ani'), ['wali' => 'ada', 'wali_murid_id' => null, 'hubungan' => 'wali']));
    }

    public function test_adding_is_logged_without_the_nik(): void
    {
        $budi = $this->siswa('Budi');
        $this->service()->tambahkan($this->operator, $budi, $this->baru(['name' => 'Ahmad', 'nik' => self::NIK]));

        $log = Activity::query()->where('event', 'wali_ditambahkan')->sole();
        $this->assertSame('Wali murid ditambahkan', $log->description);
        $this->assertTrue($log->subject->is($budi));
        $this->assertTrue($log->causer->is($this->operator));
        $this->assertSame(['wali' => 'Ahmad', 'hubungan' => 'ayah', 'utama' => true], $log->attribute_changes['attributes']);

        $dibuat = Activity::query()->where('log_name', 'pengguna')->where('event', 'dibuat')->sole();
        $this->assertFalse($dibuat->attribute_changes['attributes']['status_aktif']);
        $this->assertSame('wali', $dibuat->attribute_changes['attributes']['peran_wali']);

        foreach (Activity::query()->get() as $activity) {
            $this->assertStringNotContainsString(self::NIK, json_encode($activity->toArray()));
        }
    }

    private function service(): SiswaWaliService
    {
        return app(SiswaWaliService::class);
    }

    /**
     * A valid "new parent" payload, as the form sends it.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function baru(array $override = []): array
    {
        return [
            'wali' => 'baru',
            'wali_murid_id' => null,
            'hubungan' => 'ayah',
            'name' => 'Ahmad',
            'jenis_kelamin' => null,
            'no_hp' => null,
            'alamat' => null,
            'nik' => null,
            'pekerjaan' => null,
            ...$override,
        ];
    }

    private function siswa(string $nama): Siswa
    {
        return Siswa::factory()->for(User::factory()->state(['name' => $nama]))->create();
    }

    private function assertRejected(string $message, Closure $operation): void
    {
        try {
            $operation();
        } catch (PelanggaranAturanKesiswaan $exception) {
            $this->assertSame($message, $exception->getMessage());

            return;
        }

        $this->fail('Expected the operation to be rejected.');
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
}
