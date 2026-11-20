<?php

namespace Tests\Feature\Domains\People\Services\Import;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\PenempatanRombel;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\PelanggaranAturanAkademik;
use App\Domains\Foundation\Enums\AksiPengguna;
use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\HubunganWali;
use App\Domains\People\Enums\StatusImportSiswa;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Models\ImportSiswa;
use App\Domains\People\Models\Ptk;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Models\SiswaWali;
use App\Domains\People\Models\WaliMurid;
use App\Domains\People\Services\Import\KolomImportSiswa;
use App\Domains\People\Services\Import\TemplateImportSiswa;
use App\Domains\People\Services\ImportSiswaService;
use App\Domains\People\Services\PelanggaranAturanKesiswaan;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The student import: a check (pratinjau) that runs every row through the
 * services and rolls everything back, and a save that runs them again and
 * commits only when no row has an error.
 */
class ImportSiswaServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string NIK_GURU = '7404012345670009';

    private const string NIK_AYAH = '7404012345670001';

    private const string NIK_SISWA = '7404012345670005';

    private User $operator;

    private TahunAjaran $tahunAjaran;

    private Rombel $x1;

    private Rombel $x2;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(PeranDanIzinSeeder::class);
        $this->operator = User::factory()->operator()->create();
        $this->tahunAjaran = TahunAjaran::factory()->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26', 'status_aktif' => true]);
        $this->x1 = Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 1]);
        $this->x2 = Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 2]);
    }

    public function test_a_clean_file_is_checked_without_saving_anything(): void
    {
        $import = $this->pratinjau([
            $this->baris(['nis' => '20261001', 'nama' => 'Budi', 'ayah_nama' => 'Ahmad', 'ayah_no_hp' => '081234567890', 'ibu_nama' => 'Siti']),
            $this->baris(['nis' => '20261002', 'nama' => 'Ani', 'jenis_kelamin' => 'P', 'ayah_nama' => 'Ahmad', 'ayah_no_hp' => '0812 3456 7890']),
        ]);

        $this->assertSame(StatusImportSiswa::Siap, $import->status);
        $this->assertSame([], $import->galat);
        $this->assertSame([
            'siswa_baru' => 2,
            'siswa_diperbarui' => 0,
            'siswa_dipindah' => 0,
            'siswa_tanpa_perubahan' => 0,
            'wali_baru' => 2,
            'wali_dipakai_bersama' => 1,
            'baris_bergalat' => 0,
        ], $import->ringkasan);
        $this->assertSame(0, Siswa::query()->count());
        $this->assertSame(0, WaliMurid::query()->count());
        $this->assertTrue(Storage::disk('local')->exists($import->path));
    }

    public function test_saving_creates_students_parents_and_placements_and_deletes_the_file(): void
    {
        $import = $this->simpan([
            $this->baris(['nis' => '00123', 'nama' => 'Budi', 'nisn' => '0012345678', 'agama' => 'Islam', 'tanggal_lahir' => '01-05-2010', 'no_hp' => '081234567891',
                'ayah_nama' => 'Ahmad', 'ayah_no_hp' => '081234567890', 'ayah_pekerjaan' => 'Petani', 'ibu_nama' => 'Siti', 'ibu_meninggal' => 'ya']),
        ]);

        $budi = Siswa::query()->with('user')->where('nis', '00123')->firstOrFail();
        $this->assertSame('Budi', $budi->user->name);
        $this->assertSame('00123', $budi->user->username);
        $this->assertSame('0012345678', $budi->nisn);
        $this->assertSame('2010-05-01', $budi->user->tanggal_lahir?->toDateString());
        $this->assertSame('6281234567891', $budi->user->no_hp);
        $this->assertSame(StatusSiswa::Aktif, $budi->status);
        $this->assertSame($this->x1->id, PenempatanRombel::query()->where('siswa_id', $budi->id)->value('rombel_id'));

        $ayah = $this->wali($budi, HubunganWali::Ayah);
        $this->assertSame('Ahmad', $ayah->waliMurid->user->name);
        $this->assertSame(JenisKelamin::LakiLaki, $ayah->waliMurid->user->jenis_kelamin);
        $this->assertSame('Petani', $ayah->waliMurid->pekerjaan);
        $this->assertTrue($ayah->utama);
        $this->assertTrue($this->wali($budi, HubunganWali::Ibu)->waliMurid->meninggal);

        $this->assertSame(StatusImportSiswa::Selesai, $import->status);
        $this->assertNull($import->path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_one_row_with_an_error_saves_nothing(): void
    {
        $import = $this->pratinjau([
            $this->baris(['nis' => '20261001', 'nama' => 'Budi', 'ayah_nama' => 'Ahmad']),
            $this->baris(['nis' => '20261002', 'nama' => 'Ani', 'jenis_kelamin' => null]),
            $this->baris(['nis' => '20261003', 'nama' => 'Citra', 'jenis_kelamin' => 'P']),
        ]);

        $this->assertSame(StatusImportSiswa::Bergalat, $import->status);
        $this->assertSame(['3:jenis_kelamin'], array_keys($this->galat($import)));
        $this->assertSame(1, $import->ringkasan['baris_bergalat']);

        $this->assertRejected('Import ini belum siap disimpan. Periksa file sampai tidak ada galat.', fn () => $this->service()->simpan($import));
        $this->assertSame(0, Siswa::query()->count());
        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, WaliMurid::query()->count());
    }

    public function test_an_error_that_appears_at_save_saves_nothing(): void
    {
        $import = $this->pratinjau([
            $this->baris(['nis' => '20261001', 'nama' => 'Budi']),
            $this->baris(['nis' => '20261002', 'nama' => 'Ani', 'nisn' => '0012345678']),
        ]);
        $this->assertSame(StatusImportSiswa::Siap, $import->status);

        Siswa::factory()->for(User::factory()->state(['name' => 'Dodi']))->create(['nisn' => '0012345678']);
        $import = $this->service()->simpan($import);

        $this->assertSame(StatusImportSiswa::Bergalat, $import->status);
        $this->assertSame(['NISN ini sudah dipakai oleh Dodi.'], $this->galat($import)['3:nisn']);
        $this->assertSame(1, Siswa::query()->count());
        $this->assertTrue(Storage::disk('local')->exists((string) $import->path));
    }

    public function test_an_empty_cell_never_clears_stored_data(): void
    {
        $budi = $this->siswa('Budi', '20261001', ['nisn' => '0012345678', 'asal_sekolah' => 'SMP 1'], ['tempat_lahir' => 'Kendari', 'alamat' => 'Jl. Mawar', 'no_hp' => '6281234567891']);
        $ahmad = $this->tautkan($budi, 'Ahmad', HubunganWali::Ayah, ['no_hp' => '6281234567890'], 'Petani');

        $import = $this->simpan([['nis' => '20261001']]);

        $budi->refresh();
        $this->assertSame('0012345678', $budi->nisn);
        $this->assertSame('SMP 1', $budi->asal_sekolah);
        $this->assertSame('Kendari', $budi->user->tempat_lahir);
        $this->assertSame('Jl. Mawar', $budi->user->alamat);
        $this->assertSame('6281234567891', $budi->user->no_hp);
        $this->assertSame('Petani', $ahmad->fresh()?->pekerjaan);
        $this->assertSame(1, $import->ringkasan['siswa_tanpa_perubahan']);
    }

    public function test_a_changed_cell_updates_the_student(): void
    {
        $budi = $this->siswa('Budi', '20261001', [], ['alamat' => 'Jl. Mawar']);

        $import = $this->simpan([['nis' => '20261001', 'alamat' => 'Jl. Melati']]);

        $this->assertSame('Jl. Melati', $budi->user->fresh()?->alamat);
        $this->assertSame(1, $import->ringkasan['siswa_diperbarui']);
    }

    public function test_the_status_never_changes_and_an_inactive_student_with_a_rombel_is_refused(): void
    {
        $this->siswa('Budi', '20261001', ['status' => StatusSiswa::Lulus, 'tanggal_keluar' => '2026-06-01', 'keterangan_keluar' => 'Lulus']);
        $this->x1->update(['kapasitas' => 1]);

        // The student who left never counts against the capacity.
        $import = $this->pratinjau([['nis' => '20261001', 'rombel' => 'X-1'], $this->baris(['nis' => '20261002', 'nama' => 'Ani', 'jenis_kelamin' => 'P'])]);

        $this->assertSame(['2:rombel' => ['Budi tidak aktif dan tidak bisa ditempatkan.']], $this->galat($import));

        $import = $this->simpan([['nis' => '20261001', 'alamat' => 'Jl. Melati']]);

        $this->assertSame(StatusImportSiswa::Selesai, $import->status);
        $this->assertSame(StatusSiswa::Lulus, Siswa::query()->where('nis', '20261001')->value('status'));
    }

    public function test_a_nis_with_a_leading_zero_is_its_own_key(): void
    {
        $this->siswa('Budi', '123');

        $import = $this->simpan([$this->baris(['nis' => '0123', 'nama' => 'Ani', 'jenis_kelamin' => 'P'])]);

        $this->assertSame(1, $import->ringkasan['siswa_baru']);
        $this->assertSame('Ani', Siswa::query()->where('nis', '0123')->firstOrFail()->user->name);
        $this->assertSame('Budi', Siswa::query()->where('nis', '123')->firstOrFail()->user->name);
    }

    public function test_importing_the_same_file_again_changes_nothing_and_logs_only_the_import(): void
    {
        $baris = [
            $this->baris(['nis' => '20261001', 'nama' => 'Budi', 'tanggal_lahir' => '01-05-2010', 'no_hp' => '081234567891',
                'ayah_nama' => 'Ahmad', 'ayah_no_hp' => '081234567890', 'ayah_pekerjaan' => 'Petani', 'ibu_nama' => 'Siti', 'wali_utama' => 'ibu']),
            $this->baris(['nis' => '20261002', 'nama' => 'Ani', 'jenis_kelamin' => 'P', 'rombel' => 'X-2', 'ayah_nama' => 'Ahmad', 'ayah_no_hp' => '081234567890']),
        ];
        $this->simpan($baris);
        $logSebelum = Activity::query()->max('id');

        $import = $this->simpan($baris);

        $this->assertSame(2, $import->ringkasan['siswa_tanpa_perubahan']);
        $this->assertSame(0, $import->ringkasan['siswa_baru'] + $import->ringkasan['siswa_diperbarui'] + $import->ringkasan['siswa_dipindah'] + $import->ringkasan['wali_baru']);
        $this->assertSame(['import_siswa'], Activity::query()->where('id', '>', $logSebelum)->pluck('event')->all());
    }

    public function test_a_student_changing_rombel_is_listed_and_moved(): void
    {
        $budi = $this->siswa('Budi', '20261001');
        $this->tempatkan($budi, $this->x1);

        $import = $this->pratinjau([['nis' => '20261001', 'rombel' => 'X-2']]);

        $this->assertSame([['baris' => 2, 'nis' => '20261001', 'nama' => 'Budi', 'dari' => 'X-1', 'ke' => 'X-2']], $import->pindah);
        $this->assertSame(1, $import->ringkasan['siswa_dipindah']);

        $this->service()->simpan($import);

        $this->assertSame($this->x2->id, PenempatanRombel::query()->where('siswa_id', $budi->id)->value('rombel_id'));
        $this->assertSame(1, Activity::query()->where('event', 'siswa_dipindah_rombel')->count());
    }

    public function test_rows_that_together_overflow_a_rombel_are_errors(): void
    {
        $this->x1->update(['kapasitas' => 2]);
        $this->tempatkan($this->siswa('Dodi', '20261009'), $this->x1);

        $import = $this->pratinjau([
            $this->baris(['nis' => '20261001', 'nama' => 'Budi']),
            $this->baris(['nis' => '20261002', 'nama' => 'Ani', 'jenis_kelamin' => 'P']),
        ]);

        $pesan = 'Rombel X-1 akan berisi 3 siswa aktif, melebihi kapasitas 2. Naikkan kapasitasnya atau kurangi siswa yang masuk.';
        $this->assertSame(['2:rombel' => [$pesan], '3:rombel' => [$pesan]], $this->galat($import));
    }

    public function test_a_student_moving_out_frees_a_seat_for_an_earlier_row(): void
    {
        $this->x1->update(['kapasitas' => 1]);
        $dodi = $this->siswa('Dodi', '20261009');
        $this->tempatkan($dodi, $this->x1);

        $import = $this->simpan([
            $this->baris(['nis' => '20261001', 'nama' => 'Budi', 'rombel' => 'X-1']),
            ['nis' => '20261009', 'rombel' => 'X-2'],
        ]);

        $this->assertSame(StatusImportSiswa::Selesai, $import->status);
        $this->assertSame($this->x2->id, PenempatanRombel::query()->where('siswa_id', $dodi->id)->value('rombel_id'));
    }

    public function test_a_swap_between_two_full_rombels_is_an_error(): void
    {
        $this->x1->update(['kapasitas' => 1]);
        $this->x2->update(['kapasitas' => 1]);
        $this->tempatkan($this->siswa('Budi', '20261001'), $this->x1);
        $this->tempatkan($this->siswa('Ani', '20261002'), $this->x2);

        $import = $this->pratinjau([
            ['nis' => '20261001', 'rombel' => 'X-2'],
            ['nis' => '20261002', 'rombel' => 'X-1'],
        ]);

        $this->assertSame([
            '2:rombel' => ['Budi tidak bisa dipindah ke X-2: rombel itu penuh, dan siswa yang keluar darinya menunggu kursi di rombel lain yang juga penuh. Naikkan kapasitas X-2 sementara, lalu periksa lagi.'],
            '3:rombel' => ['Ani tidak bisa dipindah ke X-1: rombel itu penuh, dan siswa yang keluar darinya menunggu kursi di rombel lain yang juga penuh. Naikkan kapasitas X-1 sementara, lalu periksa lagi.'],
        ], $this->galat($import));
    }

    public function test_siblings_in_one_file_share_one_parent(): void
    {
        $this->simpan([
            $this->baris(['nis' => '20261001', 'nama' => 'Budi', 'ayah_nama' => 'Ahmad', 'ayah_no_hp' => '081234567890']),
            $this->baris(['nis' => '20261002', 'nama' => 'Ani', 'jenis_kelamin' => 'P', 'ayah_nama' => 'ahmad', 'ayah_no_hp' => '+62 812-3456-7890']),
        ]);

        $this->assertSame(1, WaliMurid::query()->count());
        $this->assertSame(2, SiswaWali::query()->where('hubungan', HubunganWali::Ayah->value)->count());
    }

    public function test_a_parent_is_matched_by_nik_or_by_name_and_phone(): void
    {
        $ahmad = $this->waliAda('Ahmad', ['nik' => self::NIK_AYAH]);
        $siti = $this->waliAda('Siti', ['no_hp' => '6281234567890']);

        $import = $this->simpan([
            $this->baris(['nis' => '20261001', 'nama' => 'Budi', 'ayah_nama' => 'Ahmad', 'ayah_nik' => self::NIK_AYAH, 'ibu_nama' => 'SITI', 'ibu_no_hp' => '0812-3456-7890']),
        ]);

        $budi = Siswa::query()->where('nis', '20261001')->firstOrFail();
        $this->assertSame($ahmad->id, $this->wali($budi, HubunganWali::Ayah)->wali_murid_id);
        $this->assertSame($siti->id, $this->wali($budi, HubunganWali::Ibu)->wali_murid_id);
        $this->assertSame(2, WaliMurid::query()->count());
        $this->assertSame(0, $import->ringkasan['wali_baru']);
        $this->assertSame('Siti', $siti->user->fresh()?->name);
    }

    public function test_a_parent_with_the_nik_but_another_name_or_with_the_phone_but_another_name_is_not_matched(): void
    {
        $this->waliAda('Ahmad', ['nik' => self::NIK_AYAH]);
        $bambang = $this->waliAda('Bambang', ['no_hp' => '6281234567890']);

        $import = $this->pratinjau([
            $this->baris(['nis' => '20261001', 'nama' => 'Budi', 'ayah_nama' => 'Bambang', 'ayah_nik' => self::NIK_AYAH]),
            $this->baris(['nis' => '20261002', 'nama' => 'Ani', 'jenis_kelamin' => 'P', 'ayah_nama' => 'Ahmad', 'ayah_no_hp' => '081234567890']),
        ]);

        $this->assertSame(['2:ayah_nik' => ['NIK sudah terdaftar di sistem untuk orang lain.']], $this->galat($import));

        $this->simpan([$this->baris(['nis' => '20261002', 'nama' => 'Ani', 'jenis_kelamin' => 'P', 'ayah_nama' => 'Ahmad', 'ayah_no_hp' => '081234567890'])]);

        $ani = Siswa::query()->where('nis', '20261002')->firstOrFail();
        $this->assertNotSame($bambang->id, $this->wali($ani, HubunganWali::Ayah)->wali_murid_id);
        $this->assertSame(3, WaliMurid::query()->count());
    }

    public function test_a_new_parent_needs_a_name(): void
    {
        $import = $this->pratinjau([$this->baris(['nis' => '20261001', 'nama' => 'Budi', 'ayah_no_hp' => '081234567890'])]);

        $this->assertSame(['2:ayah_nama' => ['Isi nama ayah.']], $this->galat($import));
    }

    public function test_a_died_mark_can_be_taken_back_and_the_parent_made_main_contact(): void
    {
        $budi = $this->siswa('Budi', '20261001');
        $ahmad = $this->waliAda('Ahmad', ['jenis_kelamin' => JenisKelamin::LakiLaki]);
        $ahmad->update(['meninggal' => true]);
        SiswaWali::factory()->create(['siswa_id' => $budi->id, 'wali_murid_id' => $ahmad->id, 'hubungan' => HubunganWali::Ayah, 'utama' => false]);
        $this->tautkan($budi, 'Siti', HubunganWali::Ibu);

        $this->simpan([['nis' => '20261001', 'ayah_meninggal' => 'tidak', 'wali_utama' => 'ayah']]);

        $this->assertFalse($ahmad->fresh()?->meninggal);
        $this->assertSame('Ahmad', $this->utama('20261001'));
    }

    public function test_two_matching_parents_are_an_error(): void
    {
        $this->waliAda('Ahmad', ['no_hp' => '6281234567890']);
        $this->waliAda('Ahmad', ['no_hp' => '6281234567890']);

        $import = $this->pratinjau([$this->baris(['nis' => '20261001', 'nama' => 'Budi', 'ayah_nama' => 'Ahmad', 'ayah_no_hp' => '081234567890'])]);

        $this->assertSame(['2:ayah_nama' => ['Ada 2 wali murid yang cocok dengan ayah ini. Hubungkan lewat layar Siswa.']], $this->galat($import));
    }

    public function test_one_parent_with_conflicting_cells_in_two_rows_is_an_error_on_both(): void
    {
        $import = $this->pratinjau([
            $this->baris(['nis' => '20261001', 'nama' => 'Budi', 'ayah_nama' => 'Ahmad', 'ayah_no_hp' => '081234567890', 'ayah_pekerjaan' => 'Petani']),
            $this->baris(['nis' => '20261002', 'nama' => 'Ani', 'jenis_kelamin' => 'P', 'ayah_nama' => 'Ahmad', 'ayah_no_hp' => '081234567890', 'ayah_pekerjaan' => 'Nelayan']),
            $this->baris(['nis' => '20261003', 'nama' => 'Citra', 'jenis_kelamin' => 'P', 'wali_nama' => 'Ahmad', 'wali_no_hp' => '081234567890']),
        ]);

        $pesan = 'Orang yang sama muncul di baris 2, 3, dan 4 dengan isian berbeda: pekerjaan. Samakan isiannya.';
        $this->assertSame(['2:ayah_pekerjaan' => [$pesan], '3:ayah_pekerjaan' => [$pesan], '4:wali_nama' => [$pesan]], $this->galat($import));
    }

    public function test_a_student_with_another_father_gets_an_error(): void
    {
        $budi = $this->siswa('Budi', '20261001');
        $this->tautkan($budi, 'Ahmad', HubunganWali::Ayah);

        $import = $this->pratinjau([['nis' => '20261001', 'ayah_nama' => 'Bambang']]);

        $this->assertSame(['2:ayah_nama' => ['Budi sudah punya ayah Ahmad. Ubah lewat layar Siswa.']], $this->galat($import));
    }

    public function test_the_same_parent_gets_empty_fields_filled_and_different_ones_updated(): void
    {
        $budi = $this->siswa('Budi', '20261001');
        $ahmad = $this->tautkan($budi, 'Ahmad', HubunganWali::Ayah, [], 'Petani');

        $import = $this->simpan([['nis' => '20261001', 'ayah_nama' => 'Ahmad', 'ayah_no_hp' => '081234567890', 'ayah_pekerjaan' => 'Nelayan']]);

        $ahmad->refresh();
        $this->assertSame('6281234567890', $ahmad->user->no_hp);
        $this->assertSame('Nelayan', $ahmad->pekerjaan);
        $this->assertSame(1, $import->ringkasan['siswa_diperbarui']);
        $this->assertSame(1, WaliMurid::query()->count());
    }

    public function test_the_main_contact_defaults_to_the_father_then_the_mother_then_the_guardian(): void
    {
        $this->simpan([
            $this->baris(['nis' => '20261001', 'nama' => 'Budi', 'ayah_nama' => 'Ahmad', 'ayah_meninggal' => 'ya', 'ibu_nama' => 'Siti', 'wali_nama' => 'Pak Lurah']),
            $this->baris(['nis' => '20261002', 'nama' => 'Ani', 'jenis_kelamin' => 'P', 'ayah_nama' => 'Bambang', 'wali_nama' => 'Bu Camat', 'wali_utama' => 'wali']),
        ]);

        $this->assertSame('Siti', $this->utama('20261001'));
        $this->assertSame('Bu Camat', $this->utama('20261002'));
        $this->assertTrue(WaliMurid::query()->whereRelation('user', 'name', 'Ahmad')->firstOrFail()->meninggal);
    }

    public function test_a_parent_marked_as_died_cannot_be_the_main_contact(): void
    {
        $import = $this->pratinjau([
            $this->baris(['nis' => '20261001', 'nama' => 'Budi', 'ayah_nama' => 'Ahmad', 'ayah_meninggal' => 'ya', 'ibu_nama' => 'Siti', 'wali_utama' => 'ayah']),
        ]);

        $this->assertSame(['2:wali_utama' => ['Ahmad ditandai meninggal dan tidak bisa dijadikan wali utama.']], $this->galat($import));
    }

    public function test_the_parent_gender_follows_the_relation(): void
    {
        $this->waliAda('Siti', ['no_hp' => '6281234567890', 'jenis_kelamin' => JenisKelamin::Perempuan]);

        $import = $this->pratinjau([$this->baris(['nis' => '20261001', 'nama' => 'Budi', 'ayah_nama' => 'Siti', 'ayah_no_hp' => '081234567890'])]);

        $this->assertSame(['2:ayah_nama' => ['Siti tercatat perempuan, tidak bisa menjadi ayah.']], $this->galat($import));
    }

    public function test_a_nik_held_by_someone_else_is_never_named_or_logged(): void
    {
        $this->assertTrue($this->operator->can('searchNik', User::class));
        $guru = Ptk::factory()->for(User::factory()->state(['name' => 'Hafida']))->create();
        $guru->user->isiNik(self::NIK_GURU);
        $guru->user->save();
        $dodi = $this->siswa('Dodi', '20261009');
        $dodi->user->isiNik(self::NIK_SISWA);
        $dodi->user->save();
        $budi = $this->siswa('Budi', '20261001');
        $this->tautkan($budi, 'Ahmad', HubunganWali::Ayah);

        $import = $this->pratinjau([
            ['nis' => '20261001', 'ayah_nama' => 'Ahmad', 'ayah_nik' => self::NIK_GURU],
            $this->baris(['nis' => '20261002', 'nama' => 'Ani', 'jenis_kelamin' => 'P', 'nik' => self::NIK_GURU]),
            $this->baris(['nis' => '20261003', 'nama' => 'Citra', 'jenis_kelamin' => 'P', 'ibu_nama' => 'Dewi', 'ibu_nik' => self::NIK_SISWA]),
        ]);

        $pesan = ['NIK sudah terdaftar di sistem untuk orang lain.'];
        $this->assertSame(['2:ayah_nik' => $pesan, '3:nik' => $pesan, '4:ibu_nik' => $pesan], $this->galat($import));

        $tersimpan = (string) json_encode(ImportSiswa::query()->findOrFail($import->id)->getAttributes());
        $this->assertStringNotContainsString(self::NIK_GURU, $tersimpan);
        $this->assertStringNotContainsString(self::NIK_SISWA, $tersimpan);
        $this->assertStringNotContainsString('Hafida', $tersimpan);
        $this->assertStringNotContainsString('Dodi', $tersimpan);
        $this->assertSame(0, Activity::query()->where('event', AksiPengguna::NikDicari->value)->count());
    }

    public function test_a_check_leaves_no_trace_but_its_own_record(): void
    {
        $guru = Ptk::factory()->for(User::factory()->state(['name' => 'Hafida']))->create();
        $guru->user->isiNik(self::NIK_GURU);
        $guru->user->save();
        $budi = $this->siswa('Budi', '20261001');
        $this->tempatkan($budi, $this->x1);
        $this->tautkan($budi, 'Ahmad', HubunganWali::Ayah, ['no_hp' => '6281234567890']);
        $import = $this->import([
            ['nis' => '20261001', 'alamat' => 'Jl. Melati', 'rombel' => 'X-2', 'ayah_nama' => 'Ahmad', 'ayah_nik' => self::NIK_GURU, 'ibu_nama' => 'Siti'],
            $this->baris(['nis' => '20261002', 'nama' => 'Ani', 'jenis_kelamin' => 'P', 'ayah_nama' => 'Ahmad', 'ayah_no_hp' => '081234567890', 'wali_nama' => 'Pak Lurah']),
            $this->baris(['nis' => '20261003', 'nama' => 'Citra', 'jenis_kelamin' => 'P', 'nik' => self::NIK_GURU]),
        ]);
        $sebelum = $this->jejak();

        $import = $this->service()->pratinjau($import);

        $this->assertSame(StatusImportSiswa::Bergalat, $import->status);
        $this->assertSame(1, $import->ringkasan['siswa_baru']);
        $this->assertSame($sebelum, $this->jejak());
    }

    public function test_saving_writes_one_import_log_with_the_file_name_and_counts(): void
    {
        $import = $this->simpan([$this->baris(['nis' => '20261001', 'nama' => 'Budi', 'ayah_nama' => 'Ahmad', 'ayah_nik' => self::NIK_AYAH])]);

        $log = Activity::query()->where('event', 'import_siswa')->sole();
        $this->assertSame('kesiswaan', $log->log_name);
        $this->assertTrue($log->causer?->is($this->operator));
        $this->assertTrue($log->subject?->is($import));
        $this->assertSame('siswa.xlsx', $log->properties['nama_file']);
        $this->assertSame('2026/2027', $log->properties['tahun_ajaran']);
        $this->assertSame($import->ringkasan, $log->properties['ringkasan']);
        $this->assertStringNotContainsString(self::NIK_AYAH, (string) Activity::query()->get()->toJson());
    }

    public function test_cancelling_deletes_the_file(): void
    {
        $import = $this->pratinjau([$this->baris(['nis' => '20261001', 'nama' => 'Budi'])]);

        $import = $this->service()->batal($import);

        $this->assertSame(StatusImportSiswa::Dibatalkan, $import->status);
        $this->assertNull($import->path);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertRejected('Import ini belum siap disimpan. Periksa file sampai tidak ada galat.', fn () => $this->service()->simpan($import));
    }

    public function test_only_an_uploaded_file_in_the_import_folder_is_accepted(): void
    {
        Storage::disk('local')->put('foto-pengguna/a.xlsx', 'isi');

        foreach (['foto-pengguna/a.xlsx', 'imports/../foto-pengguna/a.xlsx', 'imports/tidak-ada.xlsx'] as $path) {
            try {
                $this->service()->mulai($this->operator, $this->tahunAjaran, $path, 'siswa.xlsx');
                $this->fail("{$path} diterima.");
            } catch (ValidationException $exception) {
                $this->assertSame(['file' => ['File import tidak ditemukan. Unggah ulang file .xlsx.']], $exception->errors(), $path);
            }
        }

        $this->assertSame(0, ImportSiswa::query()->count());
    }

    public function test_a_school_year_before_the_active_one_is_refused(): void
    {
        $lalu = TahunAjaran::factory()->create(['nama' => '2025/2026', 'tanggal_mulai' => '2025-07-14', 'tanggal_selesai' => '2026-06-27']);
        $path = $this->file([$this->baris(['nis' => '20261001', 'nama' => 'Budi', 'rombel' => null])]);

        $this->assertRejected('Tahun ajaran 2025/2026 sudah lewat. Pilih rombel di tahun ajaran aktif atau sesudahnya.', fn () => $this->service()->mulai($this->operator, $lalu, $path, 'siswa.xlsx'));
    }

    public function test_the_same_nis_twice_is_an_error_on_both_rows(): void
    {
        $import = $this->pratinjau([
            $this->baris(['nis' => '20261001', 'nama' => 'Budi']),
            $this->baris(['nis' => '20261002', 'nama' => 'Ani', 'jenis_kelamin' => 'P']),
            $this->baris(['nis' => '20261001', 'nama' => 'Budi']),
        ]);

        $pesan = ['NIS ini muncul lebih dari sekali di file (baris 2 dan 4).'];
        $this->assertSame(['2:nis' => $pesan, '4:nis' => $pesan], $this->galat($import));
    }

    public function test_a_cell_that_cannot_be_read_is_an_error_and_the_file_errors_have_no_row(): void
    {
        $import = $this->pratinjau([$this->baris(['nis' => 20261001, 'nama' => 'Budi'])]);

        $this->assertSame(['2:nis'], array_keys($this->galat($import)));
        $this->assertSame(0, $import->ringkasan['siswa_baru']);

        $spreadsheet = app(TemplateImportSiswa::class)->buat($this->tahunAjaran);
        $spreadsheet->getSheetByName(KolomImportSiswa::LEMBAR_DATA)?->setCellValue('A1', 'Nomor');
        $import = $this->service()->pratinjau($this->service()->mulai($this->operator, $this->tahunAjaran, $this->simpanFile($spreadsheet), 'lama.xlsx'));

        $this->assertSame(StatusImportSiswa::Bergalat, $import->status);
        $this->assertSame([['baris' => null, 'kolom' => null, 'pesan' => 'Kolom tidak ditemukan: NIS. Pakai template terbaru.']], $import->galat);
    }

    /**
     * A full row for a new student in X-1.
     *
     * @param  array<string, mixed>  $isi
     * @return array<string, mixed>
     */
    private function baris(array $isi): array
    {
        return [...['jenis_kelamin' => 'L', 'rombel' => 'X-1'], ...$isi];
    }

    /**
     * @param  list<array<string, mixed>>  $baris
     */
    private function import(array $baris): ImportSiswa
    {
        return $this->service()->mulai($this->operator, $this->tahunAjaran, $this->file($baris), 'siswa.xlsx');
    }

    /**
     * @param  list<array<string, mixed>>  $baris
     */
    private function pratinjau(array $baris): ImportSiswa
    {
        return $this->service()->pratinjau($this->import($baris));
    }

    /**
     * @param  list<array<string, mixed>>  $baris
     */
    private function simpan(array $baris): ImportSiswa
    {
        $import = $this->pratinjau($baris);
        $this->assertSame(StatusImportSiswa::Siap, $import->status, (string) json_encode($import->galat));

        return $this->service()->simpan($import);
    }

    /**
     * The import template for the school year, filled with $baris (column
     * key => value; strings as text cells, numbers as number cells), put
     * in the import folder.
     *
     * @param  list<array<string, mixed>>  $baris
     */
    private function file(array $baris): string
    {
        $spreadsheet = app(TemplateImportSiswa::class)->buat($this->tahunAjaran);
        $sheet = $spreadsheet->getSheetByName(KolomImportSiswa::LEMBAR_DATA);
        $this->assertNotNull($sheet);

        foreach ($baris as $indeks => $nilai) {
            foreach ($nilai as $kolom => $isi) {
                $alamat = KolomImportSiswa::huruf($kolom).($indeks + 2);

                match (true) {
                    $isi === null => null,
                    is_string($isi) => $sheet->setCellValueExplicit($alamat, $isi, DataType::TYPE_STRING),
                    default => $sheet->setCellValueExplicit($alamat, $isi, DataType::TYPE_NUMERIC),
                };
            }
        }

        return $this->simpanFile($spreadsheet);
    }

    private function simpanFile(Spreadsheet $spreadsheet): string
    {
        $sementara = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($sementara);
        $spreadsheet->disconnectWorksheets();

        $path = 'imports/'.Str::uuid().'.xlsx';
        Storage::disk('local')->put($path, (string) file_get_contents($sementara));
        unlink($sementara);

        return $path;
    }

    /**
     * "baris:kolom" => messages.
     *
     * @return array<string, list<string>>
     */
    private function galat(ImportSiswa $import): array
    {
        $galat = [];

        foreach ($import->galat ?? [] as $item) {
            $galat["{$item['baris']}:{$item['kolom']}"][] = $item['pesan'];
        }

        return $galat;
    }

    /**
     * Every table but import_siswa with all its rows, and every file on the
     * disk with its content.
     *
     * @return array<string, mixed>
     */
    private function jejak(): array
    {
        $tabel = [];

        foreach (Schema::getTables() as $info) {
            if ($info['name'] !== 'import_siswa') {
                $tabel[$info['name']] = DB::table($info['name'])->get()->map(fn (object $baris): array => (array) $baris)->all();
            }
        }

        $disk = [];

        foreach (Storage::disk('local')->allFiles() as $file) {
            $disk[$file] = md5((string) Storage::disk('local')->get($file));
        }

        return ['tabel' => $tabel, 'disk' => $disk];
    }

    /**
     * @param  array<string, mixed>  $siswa
     * @param  array<string, mixed>  $akun
     */
    private function siswa(string $nama, string $nis, array $siswa = [], array $akun = []): Siswa
    {
        return Siswa::factory()
            ->for(User::factory()->state(['name' => $nama, 'jenis_kelamin' => JenisKelamin::LakiLaki, ...$akun]))
            ->create(['nis' => $nis, ...$siswa]);
    }

    /**
     * @param  array<string, mixed>  $akun
     */
    private function waliAda(string $nama, array $akun = [], ?string $pekerjaan = null): WaliMurid
    {
        $nik = $akun['nik'] ?? null;
        unset($akun['nik']);

        $wali = WaliMurid::factory()->for(User::factory()->state(['status_aktif' => false, 'jenis_kelamin' => null, 'name' => $nama, ...$akun]))->create(['pekerjaan' => $pekerjaan]);

        if (is_string($nik)) {
            $wali->user->isiNik($nik);
            $wali->user->save();
        }

        return $wali;
    }

    /**
     * @param  array<string, mixed>  $akun
     */
    private function tautkan(Siswa $siswa, string $nama, HubunganWali $hubungan, array $akun = [], ?string $pekerjaan = null): WaliMurid
    {
        $wali = $this->waliAda($nama, [...$akun, 'jenis_kelamin' => $hubungan->jenisKelamin()], $pekerjaan);
        SiswaWali::factory()->create(['siswa_id' => $siswa->id, 'wali_murid_id' => $wali->id, 'hubungan' => $hubungan, 'utama' => true]);

        return $wali;
    }

    private function tempatkan(Siswa $siswa, Rombel $rombel): void
    {
        PenempatanRombel::factory()->create(['siswa_id' => $siswa->id, 'rombel_id' => $rombel->id]);
    }

    private function wali(Siswa $siswa, HubunganWali $hubungan): SiswaWali
    {
        return SiswaWali::query()->with('waliMurid.user')->where('siswa_id', $siswa->id)->where('hubungan', $hubungan->value)->sole();
    }

    private function utama(string $nis): string
    {
        return SiswaWali::query()
            ->with('waliMurid.user')
            ->whereRelation('siswa', 'nis', $nis)
            ->where('utama', true)
            ->sole()
            ->waliMurid->user->name;
    }

    private function service(): ImportSiswaService
    {
        return app(ImportSiswaService::class);
    }

    private function assertRejected(string $pesan, \Closure $operasi): void
    {
        try {
            $operasi();
        } catch (PelanggaranAturanKesiswaan|PelanggaranAturanAkademik $exception) {
            $this->assertSame($pesan, $exception->getMessage());

            return;
        }

        $this->fail('Tidak ditolak.');
    }
}
