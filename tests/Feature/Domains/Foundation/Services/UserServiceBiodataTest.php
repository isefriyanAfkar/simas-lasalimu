<?php

namespace Tests\Feature\Domains\Foundation\Services;

use App\Domains\Foundation\Enums\Agama;
use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Biodata rules of UserService: field rules, phone normalization, NIK
 * protection (encryption, HMAC hash, duplicates, reveal), photo paths and
 * what the activity log may contain.
 */
class UserServiceBiodataTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string NIK = '7404012345670001';

    private const string NIK_LAIN = '7404012345670002';

    /**
     * HMAC-SHA256 of self::NIK with the test key in phpunit.xml.
     */
    private const string NIK_HASH = '883da60451726b178618782288e5205fb264f48026ba264c8aef7adfe48b5bed';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
    }

    public function test_saves_biodata_on_create(): void
    {
        $user = $this->create([
            'nik' => self::NIK,
            'jenis_kelamin' => 'perempuan',
            'tempat_lahir' => 'Kendari',
            'tanggal_lahir' => '1990-05-17',
            'agama' => 'Katolik',
            'alamat' => 'Jl. Pendidikan No. 1',
            'no_hp' => '081234567890',
        ])->fresh();

        $this->assertSame(self::NIK, $user->nik);
        $this->assertSame(JenisKelamin::Perempuan, $user->jenis_kelamin);
        $this->assertSame('Kendari', $user->tempat_lahir);
        $this->assertSame('1990-05-17', $user->tanggal_lahir?->toDateString());
        $this->assertSame(Agama::Katolik, $user->agama);
        $this->assertSame('Jl. Pendidikan No. 1', $user->alamat);
        $this->assertSame('6281234567890', $user->no_hp);
    }

    public function test_biodata_is_optional(): void
    {
        $user = $this->create([])->fresh();

        $this->assertNull($user->nik);
        $this->assertNull($user->nik_hash);
        $this->assertNull($user->jenis_kelamin);
        $this->assertNull($user->no_hp);
        $this->assertNull($user->foto);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nikTidakValid(): array
    {
        return [
            '15 angka' => ['740401234567000'],
            '17 angka' => ['74040123456700012'],
            'ada huruf' => ['740401234567000A'],
            'ada spasi' => ['7404 01234567000'],
        ];
    }

    #[DataProvider('nikTidakValid')]
    public function test_rejects_a_nik_that_is_not_exactly_sixteen_digits(string $nik): void
    {
        $exception = $this->captureValidation(fn () => $this->create(['nik' => $nik]));

        $this->assertArrayHasKey('nik', $exception->errors());
        $this->assertStringNotContainsString($nik, json_encode($exception->errors(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseMissing('users', ['username' => 'budi']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function enumTidakValid(): array
    {
        return [
            'jenis kelamin huruf besar' => ['jenis_kelamin', 'Laki-laki'],
            'jenis kelamin lain' => ['jenis_kelamin', 'pria'],
            'agama huruf kecil' => ['agama', 'islam'],
            'agama lain' => ['agama', 'Lainnya'],
        ];
    }

    #[DataProvider('enumTidakValid')]
    public function test_rejects_values_outside_the_enums(string $field, string $value): void
    {
        $exception = $this->captureValidation(fn () => $this->create([$field => $value]));

        $this->assertArrayHasKey($field, $exception->errors());
    }

    public function test_accepts_every_listed_religion_and_gender(): void
    {
        $agama = ['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha', 'Konghucu', 'Kepercayaan'];

        foreach ($agama as $index => $value) {
            $user = $this->create(['agama' => $value, 'jenis_kelamin' => $index % 2 === 0 ? 'laki-laki' : 'perempuan'], "user{$index}");

            $this->assertSame($value, $user->fresh()->agama?->value);
        }
    }

    public function test_birth_date_must_be_before_today(): void
    {
        $this->travelTo('2026-11-17 10:00:00');

        $this->assertArrayHasKey('tanggal_lahir', $this->captureValidation(fn () => $this->create(['tanggal_lahir' => '2026-11-17']))->errors());
        $this->assertArrayHasKey('tanggal_lahir', $this->captureValidation(fn () => $this->create(['tanggal_lahir' => '2026-11-18']))->errors());

        $user = $this->create(['tanggal_lahir' => '2026-11-16']);

        $this->assertSame('2026-11-16', $user->fresh()->tanggal_lahir?->toDateString());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nomorHpValid(): array
    {
        return [
            'awalan 08' => ['081234567890', '6281234567890'],
            'awalan +62' => ['+6281234567890', '6281234567890'],
            'awalan 62' => ['6281234567890', '6281234567890'],
            'tanda hubung' => ['0812-3456-7890', '6281234567890'],
            'spasi' => ['+62 812 3456 7890', '6281234567890'],
            'kurung dan titik' => ['(0812) 3456.7890', '6281234567890'],
            'tepi berspasi' => [' 081234567890 ', '6281234567890'],
            'tepat 10 angka' => ['081234567', '6281234567'],
            '14 angka' => ['0812345678901', '62812345678901'],
        ];
    }

    #[DataProvider('nomorHpValid')]
    public function test_normalizes_phone_numbers_to_the_62_format(string $input, string $expected): void
    {
        $user = $this->create(['no_hp' => $input]);

        $this->assertSame($expected, $user->fresh()->no_hp);
    }

    public function test_accepts_a_phone_number_of_exactly_fifteen_digits(): void
    {
        $user = $this->create(['no_hp' => '628123456789012']);

        $this->assertSame('628123456789012', $user->fresh()->no_hp);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nomorHpTidakValid(): array
    {
        return [
            '9 angka' => ['08123456'],
            '16 angka' => ['6281234567890123'],
            'awalan lain' => ['1234567890'],
            'kode negara lain' => ['+1 555 123 4567'],
            'ada huruf' => ['0812abc34567'],
            'hanya tanda' => ['+-'],
            'nol setelah +62' => ['+62 0812 3456 7890'],
            'awalan 0062' => ['0062 812 3456 7890'],
            'nol setelah 62' => ['6208123456789'],
        ];
    }

    #[DataProvider('nomorHpTidakValid')]
    public function test_rejects_invalid_phone_numbers(string $input): void
    {
        $exception = $this->captureValidation(fn () => $this->create(['no_hp' => $input]));

        $this->assertArrayHasKey('no_hp', $exception->errors());
    }

    public function test_stores_the_nik_encrypted_and_its_hmac_hash(): void
    {
        $user = $this->create(['nik' => self::NIK]);

        $raw = DB::table('users')->where('id', $user->id)->first(['nik', 'nik_hash']);

        $this->assertNotNull($raw->nik);
        $this->assertNotSame(self::NIK, $raw->nik);
        $this->assertStringNotContainsString(self::NIK, (string) $raw->nik);
        $this->assertStringNotContainsString(self::NIK, base64_decode((string) $raw->nik));
        $this->assertSame(self::NIK_HASH, $raw->nik_hash);
        $this->assertSame(self::NIK, $user->fresh()->nik);
    }

    public function test_finds_a_user_by_nik_through_the_hash(): void
    {
        $user = $this->create(['nik' => self::NIK]);
        $this->create(['nik' => self::NIK_LAIN], 'siti');

        $this->assertTrue(User::query()->denganNik(self::NIK)->sole()->is($user));
        $this->assertFalse(User::query()->denganNik('7404012345679999')->exists());
    }

    public function test_rejects_a_nik_that_another_user_already_has(): void
    {
        $this->create(['nik' => self::NIK]);

        $exception = $this->captureValidation(fn () => $this->create(['nik' => self::NIK], 'siti'));

        $this->assertSame(['NIK ini sudah terdaftar pada pengguna lain.'], $exception->errors()['nik']);
        $this->assertDatabaseMissing('users', ['username' => 'siti']);
    }

    public function test_a_concurrent_save_with_the_same_nik_ends_as_a_field_error(): void
    {
        $superAdmin = $this->superAdmin();
        $inserted = false;

        User::creating(function () use (&$inserted): void {
            if ($inserted) {
                return;
            }

            $inserted = true;

            DB::table('users')->insert([
                'name' => 'Penyusup',
                'username' => 'penyusup',
                'password' => 'x',
                'status_aktif' => true,
                'nik_hash' => self::NIK_HASH,
            ]);
        });

        $exception = $this->captureValidation(fn () => $this->create(['nik' => self::NIK], 'budi', $superAdmin));

        $this->assertSame(['NIK ini baru saja didaftarkan untuk pengguna lain. Periksa lagi, lalu simpan.'], $exception->errors()['nik']);
        $this->assertStringNotContainsString(self::NIK, $exception->getMessage());
    }

    public function test_keeping_the_own_nik_is_not_a_duplicate(): void
    {
        $user = $this->create(['nik' => self::NIK]);

        $this->update($user, ['nik' => self::NIK, 'tempat_lahir' => 'Kolaka']);

        $this->assertSame(self::NIK, $user->fresh()->nik);
        $this->assertSame('Kolaka', $user->fresh()->tempat_lahir);
    }

    public function test_an_empty_nik_on_update_keeps_the_stored_nik(): void
    {
        $user = $this->create(['nik' => self::NIK]);

        $this->update($user, ['nik' => null]);
        $this->update($user, ['nik' => '']);

        $this->assertSame(self::NIK, $user->fresh()->nik);
        $this->assertSame(self::NIK_HASH, $user->fresh()->nik_hash);
    }

    public function test_a_new_nik_on_update_replaces_the_stored_one(): void
    {
        $user = $this->create(['nik' => self::NIK]);

        $this->update($user, ['nik' => self::NIK_LAIN]);

        $this->assertSame(self::NIK_LAIN, $user->fresh()->nik);
        $this->assertFalse(User::query()->denganNik(self::NIK)->exists());
        $this->assertTrue(User::query()->denganNik(self::NIK_LAIN)->exists());
    }

    public function test_hapus_nik_clears_the_nik_and_its_hash(): void
    {
        $user = $this->create(['nik' => self::NIK]);

        $this->update($user, ['hapus_nik' => true]);

        $raw = DB::table('users')->where('id', $user->id)->first(['nik', 'nik_hash']);
        $this->assertNull($raw->nik);
        $this->assertNull($raw->nik_hash);
    }

    public function test_rejects_hapus_nik_together_with_a_new_nik(): void
    {
        $user = $this->create(['nik' => self::NIK]);

        $exception = $this->captureValidation(fn () => $this->update($user, ['nik' => self::NIK_LAIN, 'hapus_nik' => true]));

        $this->assertArrayHasKey('nik', $exception->errors());
        $this->assertSame(self::NIK, $user->fresh()->nik);
    }

    public function test_update_without_biodata_keys_keeps_the_biodata(): void
    {
        $user = $this->create(['nik' => self::NIK, 'agama' => 'Hindu', 'no_hp' => '081234567890']);

        $this->service()->update($this->superAdmin(), $user, [
            'name' => 'Nama Baru',
            'username' => 'budi',
            'email' => null,
            'peran' => Peran::Operator->value,
        ]);

        $user = $user->fresh();
        $this->assertSame('Nama Baru', $user->name);
        $this->assertSame(self::NIK, $user->nik);
        $this->assertSame(Agama::Hindu, $user->agama);
        $this->assertSame('6281234567890', $user->no_hp);
    }

    public function test_biodata_fields_can_be_emptied_on_update(): void
    {
        $user = $this->create(['agama' => 'Hindu', 'no_hp' => '081234567890', 'tempat_lahir' => 'Kendari']);

        $this->update($user, ['agama' => null, 'no_hp' => '', 'tempat_lahir' => null]);

        $user = $user->fresh();
        $this->assertNull($user->agama);
        $this->assertNull($user->no_hp);
        $this->assertNull($user->tempat_lahir);
    }

    public function test_a_nik_cannot_be_saved_without_the_hmac_key(): void
    {
        config(['simas.nik_hmac_key' => null]);

        $exception = $this->captureValidation(fn () => $this->create(['nik' => self::NIK]));

        $this->assertSame(['NIK belum bisa disimpan: kunci NIK belum diatur di server.'], $exception->errors()['nik']);
        $this->assertDatabaseMissing('users', ['username' => 'budi']);
    }

    public function test_a_user_without_nik_can_be_saved_without_the_hmac_key(): void
    {
        config(['simas.nik_hmac_key' => null]);

        $user = $this->create(['agama' => 'Islam']);

        $this->assertSame(Agama::Islam, $user->fresh()->agama);
    }

    public function test_accepts_a_photo_stored_in_the_photo_folder(): void
    {
        Storage::fake('local');
        $this->putImage('foto-pengguna/budi.png');

        $user = $this->create(['foto' => 'foto-pengguna/budi.png']);

        $this->assertSame('foto-pengguna/budi.png', $user->fresh()->foto);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fotoTidakValid(): array
    {
        return [
            'di luar folder' => ['lain/budi.png'],
            'naik folder' => ['foto-pengguna/../lain/budi.png'],
            'garis miring terbalik' => ['foto-pengguna\\budi.png'],
            'tidak ada di disk' => ['foto-pengguna/tidak-ada.png'],
            'bukan gambar' => ['foto-pengguna/dokumen.png'],
            'gif' => ['foto-pengguna/animasi.gif'],
            'lebih dari 1 MB' => ['foto-pengguna/besar.png'],
        ];
    }

    #[DataProvider('fotoTidakValid')]
    public function test_rejects_an_invalid_photo(string $path): void
    {
        Storage::fake('local');
        $this->putImage('lain/budi.png');
        Storage::disk('local')->put('foto-pengguna/dokumen.png', 'bukan gambar');
        Storage::disk('local')->put('foto-pengguna/animasi.gif', UploadedFile::fake()->image('animasi.gif')->getContent());
        Storage::disk('local')->put('foto-pengguna/besar.png', UploadedFile::fake()->image('besar.png')->getContent().str_repeat("\0", 1024 * 1024));

        $exception = $this->captureValidation(fn () => $this->create(['foto' => $path]));

        $this->assertSame(['Foto tidak valid. Unggah JPG, PNG, atau WebP paling besar 1 MB.'], $exception->errors()['foto']);
    }

    public function test_accepts_a_photo_of_exactly_one_megabyte(): void
    {
        Storage::fake('local');
        $image = UploadedFile::fake()->image('pas.png')->getContent();
        Storage::disk('local')->put('foto-pengguna/pas.png', $image.str_repeat("\0", 1024 * 1024 - strlen($image)));

        $user = $this->create(['foto' => 'foto-pengguna/pas.png']);

        $this->assertSame('foto-pengguna/pas.png', $user->fresh()->foto);
    }

    public function test_deletes_the_old_photo_when_it_is_replaced(): void
    {
        Storage::fake('local');
        $this->putImage('foto-pengguna/lama.png');
        $this->putImage('foto-pengguna/baru.png');
        $user = $this->create(['foto' => 'foto-pengguna/lama.png']);

        $this->update($user, ['foto' => 'foto-pengguna/baru.png']);

        Storage::disk('local')->assertMissing('foto-pengguna/lama.png');
        Storage::disk('local')->assertExists('foto-pengguna/baru.png');
    }

    public function test_deletes_the_old_photo_when_it_is_removed(): void
    {
        Storage::fake('local');
        $this->putImage('foto-pengguna/lama.png');
        $user = $this->create(['foto' => 'foto-pengguna/lama.png']);

        $this->update($user, ['foto' => null]);

        $this->assertNull($user->fresh()->foto);
        Storage::disk('local')->assertMissing('foto-pengguna/lama.png');
    }

    public function test_keeps_the_old_photo_when_saving_fails(): void
    {
        Storage::fake('local');
        $this->putImage('foto-pengguna/lama.png');
        $this->putImage('foto-pengguna/baru.png');
        $user = $this->create(['foto' => 'foto-pengguna/lama.png']);

        $this->captureValidation(fn () => $this->update($user, ['foto' => 'foto-pengguna/baru.png', 'no_hp' => 'salah']));

        $this->assertSame('foto-pengguna/lama.png', $user->fresh()->foto);
        Storage::disk('local')->assertExists('foto-pengguna/lama.png');
    }

    public function test_never_deletes_an_old_photo_outside_the_photo_folder(): void
    {
        Storage::fake('local');
        $this->putImage('lain/penting.png');
        $this->putImage('foto-pengguna/baru.png');
        $user = $this->create([]);
        DB::table('users')->where('id', $user->id)->update(['foto' => 'lain/penting.png']);

        $this->update($user->fresh(), ['foto' => 'foto-pengguna/baru.png']);

        Storage::disk('local')->assertExists('lain/penting.png');
    }

    public function test_the_activity_log_never_holds_the_nik_or_its_ciphertext(): void
    {
        $user = $this->create(['nik' => self::NIK]);
        $ciphertext = (string) DB::table('users')->where('id', $user->id)->value('nik');

        $this->update($user, ['nik' => self::NIK_LAIN]);
        $this->update($user->fresh(), ['hapus_nik' => true]);

        $log = DB::table('activity_log')->get()->toJson();

        $this->assertStringNotContainsString(self::NIK, $log);
        $this->assertStringNotContainsString(self::NIK_LAIN, $log);
        $this->assertStringNotContainsString(substr($ciphertext, 0, 40), $log);
        $this->assertStringNotContainsString(self::NIK_HASH, $log);
    }

    public function test_logs_nik_changes_as_markers_without_values(): void
    {
        $superAdmin = $this->superAdmin();
        $user = $this->create(['nik' => self::NIK], 'budi', $superAdmin);

        $this->assertSame('diisi', $this->latestActivity('dibuat', $user)->attribute_changes['attributes']['nik']);

        $this->update($user, ['nik' => self::NIK_LAIN], $superAdmin);
        $diubah = $this->latestActivity('diubah', $user);
        $this->assertSame(['nik' => 'diubah'], $diubah->attribute_changes['attributes']);
        $this->assertArrayNotHasKey('nik', $diubah->attribute_changes['old'] ?? []);

        $this->update($user->fresh(), ['hapus_nik' => true], $superAdmin);
        $dihapus = $this->latestActivity('diubah', $user);
        $this->assertSame(['nik' => 'dihapus'], $dihapus->attribute_changes['attributes']);
        $this->assertArrayNotHasKey('nik', $dihapus->attribute_changes['old'] ?? []);
    }

    public function test_logs_photo_changes_as_markers_without_paths(): void
    {
        Storage::fake('local');
        $this->putImage('foto-pengguna/lama.png');
        $this->putImage('foto-pengguna/baru.png');
        $user = $this->create(['foto' => 'foto-pengguna/lama.png']);

        $this->assertSame('diunggah', $this->latestActivity('dibuat', $user)->attribute_changes['attributes']['foto']);

        $this->update($user, ['foto' => 'foto-pengguna/baru.png']);
        $this->assertSame(['foto' => 'diganti'], $this->latestActivity('diubah', $user)->attribute_changes['attributes']);

        $this->update($user->fresh(), ['foto' => null]);
        $this->assertSame(['foto' => 'dihapus'], $this->latestActivity('diubah', $user)->attribute_changes['attributes']);

        $this->assertStringNotContainsString('foto-pengguna/', DB::table('activity_log')->get()->toJson());
    }

    public function test_logs_other_biodata_changes_with_old_and_new_values(): void
    {
        $user = $this->create(['no_hp' => '081234567890', 'agama' => 'Islam', 'tanggal_lahir' => '1990-05-17']);

        $this->update($user, ['no_hp' => '081299998888', 'agama' => 'Kristen', 'tanggal_lahir' => '1991-01-02']);

        $activity = $this->latestActivity('diubah', $user);
        $this->assertEquals(['no_hp' => '6281299998888', 'agama' => 'Kristen', 'tanggal_lahir' => '1991-01-02'], $activity->attribute_changes['attributes']);
        $this->assertEquals(['no_hp' => '6281234567890', 'agama' => 'Islam', 'tanggal_lahir' => '1990-05-17'], $activity->attribute_changes['old']);
    }

    public function test_create_log_lists_only_filled_biodata(): void
    {
        $user = $this->create(['agama' => 'Buddha']);

        $attributes = $this->latestActivity('dibuat', $user)->attribute_changes['attributes'];

        $this->assertSame('Buddha', $attributes['agama']);
        $this->assertArrayNotHasKey('no_hp', $attributes);
        $this->assertArrayNotHasKey('nik', $attributes);
        $this->assertArrayNotHasKey('foto', $attributes);
    }

    public function test_an_operator_with_view_nik_permission_reveals_the_nik_and_it_is_logged(): void
    {
        $operator = User::factory()->operator()->create();
        $user = $this->userWithoutRole(self::NIK);

        $nik = $this->service()->tampilkanNik($operator, $user);

        $this->assertSame(self::NIK, $nik);

        $activity = Activity::query()->where('event', 'nik_ditampilkan')->sole();
        $this->assertSame('pengguna', $activity->log_name);
        $this->assertSame('NIK ditampilkan', $activity->description);
        $this->assertTrue($activity->causer?->is($operator));
        $this->assertTrue($activity->subject?->is($user));
        $this->assertNotNull($activity->created_at);
        $this->assertStringNotContainsString(self::NIK, json_encode($activity->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_a_super_admin_reveals_the_nik(): void
    {
        $user = $this->create(['nik' => self::NIK]);

        $this->assertSame(self::NIK, $this->service()->tampilkanNik($this->superAdmin(), $user));
    }

    public function test_a_user_without_view_nik_permission_cannot_reveal_and_nothing_is_logged(): void
    {
        $tanpaRole = User::factory()->create();
        $user = $this->create(['nik' => self::NIK]);

        try {
            $this->service()->tampilkanNik($tanpaRole, $user);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException $exception) {
            $this->assertStringNotContainsString(self::NIK, $exception->getMessage());
        }

        $this->assertFalse(Activity::query()->where('event', 'nik_ditampilkan')->exists());
    }

    public function test_an_operator_cannot_reveal_the_nik_of_a_user_it_may_not_manage(): void
    {
        $operator = User::factory()->operator()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $superAdmin->isiNik(self::NIK);
        $superAdmin->save();
        $otherOperator = $this->create(['nik' => self::NIK_LAIN], 'operator.lain');

        foreach ([$superAdmin, $otherOperator] as $target) {
            try {
                $this->service()->tampilkanNik($operator, $target);
                $this->fail('Expected AuthorizationException.');
            } catch (AuthorizationException) {
                // expected
            }
        }

        $this->assertFalse(Activity::query()->where('event', 'nik_ditampilkan')->exists());
    }

    public function test_an_operator_may_reveal_its_own_nik(): void
    {
        $operator = User::factory()->operator()->create();
        $operator->isiNik(self::NIK);
        $operator->save();

        $this->assertSame(self::NIK, $this->service()->tampilkanNik($operator, $operator));
    }

    public function test_cari_nik_finds_the_user_through_the_hash_and_logs_without_the_nik(): void
    {
        $superAdmin = $this->superAdmin();
        $user = $this->create(['nik' => self::NIK]);

        $found = $this->service()->cariNik($superAdmin, ' '.self::NIK.' ');

        $this->assertTrue($found?->is($user));

        $activity = Activity::query()->where('event', 'nik_dicari')->sole();
        $this->assertSame('pengguna', $activity->log_name);
        $this->assertSame('NIK dicari', $activity->description);
        $this->assertTrue($activity->causer?->is($superAdmin));
        $this->assertTrue($activity->subject?->is($user));
        $this->assertStringNotContainsString(self::NIK, DB::table('activity_log')->get()->toJson());
        $this->assertStringNotContainsString(self::NIK_HASH, DB::table('activity_log')->get()->toJson());
    }

    public function test_cari_nik_without_a_match_returns_null_and_still_logs(): void
    {
        $superAdmin = $this->superAdmin();
        $this->create(['nik' => self::NIK]);

        $this->assertNull($this->service()->cariNik($superAdmin, self::NIK_LAIN));

        $activity = Activity::query()->where('event', 'nik_dicari')->sole();
        $this->assertTrue($activity->causer?->is($superAdmin));
        $this->assertNull($activity->subject_id);
        $this->assertStringNotContainsString(self::NIK_LAIN, DB::table('activity_log')->get()->toJson());
    }

    public function test_cari_nik_hides_a_user_the_searcher_may_not_manage(): void
    {
        $operator = User::factory()->operator()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $superAdmin->isiNik(self::NIK);
        $superAdmin->save();
        $managed = $this->userWithoutRole(self::NIK_LAIN);

        $this->assertNull($this->service()->cariNik($operator, self::NIK));
        $this->assertTrue($this->service()->cariNik($operator, self::NIK_LAIN)?->is($managed));
        $this->assertSame(1, Activity::query()->where('event', 'nik_dicari')->whereNull('subject_id')->count());
    }

    public function test_cari_nik_requires_the_view_nik_permission(): void
    {
        $tanpaRole = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        try {
            $this->service()->cariNik($tanpaRole, self::NIK);
        } finally {
            $this->assertFalse(Activity::query()->where('event', 'nik_dicari')->exists());
        }
    }

    public function test_cari_nik_rejects_an_invalid_nik_without_echoing_it_or_logging(): void
    {
        $exception = $this->captureValidation(fn () => $this->service()->cariNik($this->superAdmin(), '740401234567000'));

        $this->assertSame(['NIK harus terdiri dari tepat 16 digit angka.'], $exception->errors()['nik']);
        $this->assertFalse(Activity::query()->where('event', 'nik_dicari')->exists());
    }

    public function test_cari_nik_without_the_hmac_key_is_a_field_error(): void
    {
        config(['simas.nik_hmac_key' => null]);

        $exception = $this->captureValidation(fn () => $this->service()->cariNik($this->superAdmin(), self::NIK));

        $this->assertSame(['NIK belum bisa dicari: kunci NIK belum diatur di server.'], $exception->errors()['nik']);
    }

    public function test_rejects_a_photo_that_belongs_to_another_user(): void
    {
        Storage::fake('local');
        $this->putImage('foto-pengguna/milik-siti.png');
        $siti = $this->create(['foto' => 'foto-pengguna/milik-siti.png'], 'siti');
        $budi = $this->create([]);

        $exception = $this->captureValidation(fn () => $this->update($budi, ['foto' => 'foto-pengguna/milik-siti.png']));
        $this->assertSame([UserService::PESAN_FOTO], $exception->errors()['foto']);

        $exception = $this->captureValidation(fn () => $this->service()->updateOwnProfile($budi, ['name' => $budi->name, 'foto' => 'foto-pengguna/milik-siti.png']));
        $this->assertSame([UserService::PESAN_FOTO], $exception->errors()['foto']);

        $this->assertNull($budi->fresh()->foto);
        $this->assertSame('foto-pengguna/milik-siti.png', $siti->fresh()->foto);
    }

    public function test_photos_are_stored_on_the_private_disk(): void
    {
        $this->assertSame('local', UserService::FOTO_DISK);
        $this->assertStringNotContainsString(storage_path('app/public'), Storage::disk(UserService::FOTO_DISK)->path('foto-pengguna/x.png'));
    }

    public function test_revealing_a_missing_nik_is_rejected(): void
    {
        $user = $this->create([]);

        $this->expectException(PelanggaranAturanPengguna::class);
        $this->expectExceptionMessage('Pengguna ini belum punya NIK.');

        $this->service()->tampilkanNik($this->superAdmin(), $user);
    }

    public function test_own_profile_changes_phone_address_and_photo(): void
    {
        Storage::fake('local');
        $this->putImage('foto-pengguna/lama.png');
        $this->putImage('foto-pengguna/baru.png');
        $user = $this->create(['foto' => 'foto-pengguna/lama.png', 'no_hp' => '081234567890']);

        $this->service()->updateOwnProfile($user, [
            'name' => $user->name,
            'no_hp' => '+62 812 9999 8888',
            'alamat' => 'Jl. Baru',
            'foto' => 'foto-pengguna/baru.png',
        ]);

        $user = $user->fresh();
        $this->assertSame('6281299998888', $user->no_hp);
        $this->assertSame('Jl. Baru', $user->alamat);
        $this->assertSame('foto-pengguna/baru.png', $user->foto);
        Storage::disk('local')->assertMissing('foto-pengguna/lama.png');

        $activity = $this->latestActivity('profil_diubah', $user);
        $this->assertSame(['no_hp' => '6281299998888', 'alamat' => 'Jl. Baru', 'foto' => 'diganti'], $activity->attribute_changes['attributes']);
        $this->assertSame(['no_hp' => '6281234567890', 'alamat' => null], $activity->attribute_changes['old']);
    }

    public function test_own_profile_ignores_nik_and_other_locked_biodata(): void
    {
        $user = $this->create([
            'nik' => self::NIK,
            'jenis_kelamin' => 'laki-laki',
            'tempat_lahir' => 'Kendari',
            'tanggal_lahir' => '1990-05-17',
            'agama' => 'Islam',
        ]);

        $this->service()->updateOwnProfile($user, [
            'name' => $user->name,
            'nik' => self::NIK_LAIN,
            'nik_hash' => str_repeat('0', 64),
            'hapus_nik' => true,
            'jenis_kelamin' => 'perempuan',
            'tempat_lahir' => 'Lain',
            'tanggal_lahir' => '2000-01-01',
            'agama' => 'Hindu',
        ]);

        $user = $user->fresh();
        $this->assertSame(self::NIK, $user->nik);
        $this->assertSame(self::NIK_HASH, $user->nik_hash);
        $this->assertSame(JenisKelamin::LakiLaki, $user->jenis_kelamin);
        $this->assertSame('Kendari', $user->tempat_lahir);
        $this->assertSame('1990-05-17', $user->tanggal_lahir?->toDateString());
        $this->assertSame(Agama::Islam, $user->agama);
    }

    public function test_own_profile_validates_phone_and_photo(): void
    {
        Storage::fake('local');
        $user = $this->create(['no_hp' => '081234567890']);

        $exception = $this->captureValidation(fn () => $this->service()->updateOwnProfile($user, [
            'name' => $user->name,
            'no_hp' => '123',
            'foto' => 'lain/rahasia.png',
        ]));

        $this->assertArrayHasKey('no_hp', $exception->errors());
        $this->assertArrayHasKey('foto', $exception->errors());
        $this->assertSame('6281234567890', $user->fresh()->no_hp);
    }

    public function test_serialization_never_contains_the_nik_or_its_hash(): void
    {
        $user = $this->create(['nik' => self::NIK])->fresh();

        $this->assertArrayNotHasKey('nik', $user->toArray());
        $this->assertArrayNotHasKey('nik_hash', $user->toArray());
        $this->assertStringNotContainsString(self::NIK, $user->toJson());
    }

    /**
     * @param  array<string, mixed>  $biodata
     */
    private function create(array $biodata, string $username = 'budi', ?User $pelaku = null): User
    {
        return $this->service()->create($pelaku ?? $this->superAdmin(), [
            'name' => 'Budi Santoso',
            'username' => $username,
            'email' => null,
            'password' => 'rahasia-baru-123',
            'peran' => Peran::Operator->value,
            ...$biodata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $biodata
     */
    private function update(User $user, array $biodata, ?User $pelaku = null): User
    {
        return $this->service()->update($pelaku ?? $this->superAdmin(), $user, [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'peran' => Peran::Operator->value,
            ...$biodata,
        ]);
    }

    /**
     * A user an operator may manage (no role).
     */
    private function userWithoutRole(string $nik): User
    {
        $user = User::factory()->create();
        $user->isiNik($nik);
        $user->save();

        return $user;
    }

    private function superAdmin(): User
    {
        return User::query()->role(Peran::SuperAdmin->value)->first()
            ?? User::factory()->superAdmin()->create();
    }

    private function putImage(string $path): void
    {
        Storage::disk('local')->put($path, UploadedFile::fake()->image(basename($path))->getContent());
    }

    private function captureValidation(callable $operation): ValidationException
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            return $exception;
        }

        $this->fail('Expected a ValidationException.');
    }

    private function latestActivity(string $event, User $subject): Activity
    {
        return Activity::query()
            ->where('event', $event)
            ->where('subject_id', $subject->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function service(): UserService
    {
        return app(UserService::class);
    }
}
