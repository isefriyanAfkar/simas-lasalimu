<?php

namespace App\Domains\People\Services\Import;

use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\PelanggaranAturanAkademik;
use App\Domains\Academic\Services\PenempatanRombelService;
use App\Domains\Academic\Services\RombelSiswa;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\KunciNikTidakSiap;
use App\Domains\Foundation\Services\KunciSekaliPerTransaksi;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Enums\HubunganWali;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Models\SiswaWali;
use App\Domains\People\Models\WaliMurid;
use App\Domains\People\Services\PelanggaranAturanKesiswaan;
use App\Domains\People\Services\SiswaService;
use App\Domains\People\Services\SiswaWaliService;
use App\Domains\People\Services\WaliMuridService;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * Applies the rows of an import file through the services, inside the
 * caller's transaction, and reports what happened. The caller decides
 * whether to keep it (ImportSiswaService: a check always rolls back, a save
 * commits only when there is no error). No shortcut: every write goes
 * through SiswaService, SiswaWaliService, WaliMuridService,
 * PenempatanRombelService and so UserService, with all their rules and log
 * entries.
 *
 * Before any row:
 * - all rows of siswa, wali_murid and users are locked, in the lock order
 *   S → W → users of SiswaWaliService; every later lock of those tables is
 *   a row the transaction already holds. The keys "siswa" and "users" are
 *   the ones SiswaService and UserService use with KunciSekaliPerTransaksi,
 *   so they skip their own scans;
 * - a row with a cell that could not be read is not applied;
 * - a NIS that appears in more than one row is an error on each of them;
 * - one person (the same NIK, or the same name and phone number) whose
 *   parent cells differ between rows is an error on each of those rows.
 *
 * Per row, in a savepoint that rolls back the whole row on any error:
 * 1. the student, found by NIS: created, or updated with the filled cells
 *    only (an empty cell never changes or clears anything; the status is
 *    never touched);
 * 2. ayah, ibu, wali: see terapkanWali();
 * 3. the main contact (wali_utama, or by default the current one while it
 *    lives, else ayah, ibu, wali); a parent marked as died never becomes it;
 * 4. the died marks (taking one back comes before step 3, setting one
 *    after it).
 *
 * After every row, the rombel (terapkanRombel()): the final number of
 * active students per rombel is checked against its capacity for the whole
 * file first; then moves and placements run in an order that frees seats
 * before filling them.
 *
 * Messages never contain a NIK. A NIK held by someone else always gives
 * UserService::PESAN_NIK_ORANG_LAIN (the caller runs this under
 * UserService::tanpaMenyebutPemilikNik()), and a parent found by NIK is
 * taken only when the name in the file is the stored one, so no message
 * names a person the file did not name.
 */
final class ProsesImportSiswa
{
    /**
     * @var list<string>
     */
    private const array PERAN = ['ayah', 'ibu', 'wali'];

    /**
     * Service field => template column of a student row.
     *
     * @var array<string, string>
     */
    private const array KOLOM_SISWA = [
        'name' => 'nama',
        'nis' => 'nis',
        'username' => 'nis',
        'nisn' => 'nisn',
        'nik' => 'nik',
        'jenis_kelamin' => 'jenis_kelamin',
        'agama' => 'agama',
        'tempat_lahir' => 'tempat_lahir',
        'tanggal_lahir' => 'tanggal_lahir',
        'alamat' => 'alamat',
        'no_hp' => 'no_hp',
        'tanggal_masuk' => 'tanggal_masuk',
        'asal_sekolah' => 'asal_sekolah',
    ];

    /**
     * Service field => cell of a parent block (ayah_nama, ibu_no_hp, ...).
     *
     * @var array<string, string>
     */
    private const array KOLOM_WALI = [
        'name' => 'nama',
        'no_hp' => 'no_hp',
        'nik' => 'nik',
        'pekerjaan' => 'pekerjaan',
    ];

    /**
     * The cells of a parent block that must agree between rows naming the
     * same person, with their label in messages.
     *
     * @var array<string, string>
     */
    private const array ISIAN_WALI = [
        'nama' => 'nama',
        'no_hp' => 'nomor HP',
        'nik' => 'NIK',
        'pekerjaan' => 'pekerjaan',
        'meninggal' => 'meninggal',
    ];

    /**
     * Excel row => [column key or null, message].
     *
     * @var array<int, list<array{string|null, string}>>
     */
    private array $galat = [];

    public function __construct(
        private SiswaService $siswaService,
        private SiswaWaliService $siswaWaliService,
        private WaliMuridService $waliMuridService,
        private PenempatanRombelService $penempatanRombel,
        private RombelSiswa $rombelSiswa,
    ) {}

    /**
     * Must run inside a transaction, which this never ends.
     *
     * @param  list<BarisImportSiswa>  $semuaBaris
     */
    public function jalankan(User $pelaku, TahunAjaran $tahunAjaran, array $semuaBaris): HasilImportSiswa
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ProsesImportSiswa must run inside a transaction.');
        }

        $this->galat = [];
        $this->kunciSemua();

        foreach ($semuaBaris as $baris) {
            foreach ($baris->galat as $kolom => $pesan) {
                $this->catat($baris->nomor, $kolom, $pesan);
            }
        }

        $this->periksaNisGanda($semuaBaris);
        $this->periksaWaliBertentangan($semuaBaris);

        /** @var array<int, array{siswa: Siswa, baru: bool, berubah: bool, waliBaru: int, waliDipakai: list<int>}> $hasilBaris */
        $hasilBaris = [];

        foreach ($semuaBaris as $baris) {
            if (isset($this->galat[$baris->nomor])) {
                continue;
            }

            try {
                $hasilBaris[$baris->nomor] = DB::transaction(fn (): array => $this->terapkanBaris($pelaku, $baris));
            } catch (GalatBarisImport $exception) {
                foreach ($exception->galat as [$kolom, $pesan]) {
                    $this->catat($baris->nomor, $kolom, $pesan);
                }
            }
        }

        [$pindah, $jenisRombel] = $this->terapkanRombel($pelaku, $tahunAjaran, $semuaBaris, $hasilBaris);

        return new HasilImportSiswa($this->ringkasan($hasilBaris, $jenisRombel), $this->daftarGalat(), $pindah);
    }

    /**
     * Steps S, W and users of the lock order, each over the whole table.
     */
    private function kunciSemua(): void
    {
        KunciSekaliPerTransaksi::kunci('siswa', fn () => Siswa::query()->lockForUpdate()->get(['id']));
        KunciSekaliPerTransaksi::kunci('wali_murid', fn () => WaliMurid::query()->lockForUpdate()->get(['id']));
        KunciSekaliPerTransaksi::kunci('users', fn () => User::query()->lockForUpdate()->get(['id']));
    }

    /**
     * @return array{siswa: Siswa, baru: bool, berubah: bool, waliBaru: int, waliDipakai: list<int>}
     *
     * @throws GalatBarisImport
     */
    private function terapkanBaris(User $pelaku, BarisImportSiswa $baris): array
    {
        $nilai = $baris->nilai;
        $logAwal = self::logTerakhir();
        [$siswa, $baru] = $this->terapkanSiswa($pelaku, $nilai);

        $galat = [];
        $dipakai = [];
        $waliBaru = 0;

        foreach (self::PERAN as $peran) {
            $blok = self::blok($nilai, $peran);

            if ($blok === null) {
                continue;
            }

            try {
                [$dipakai[$peran], $dibuat] = $this->terapkanWali($pelaku, $siswa, $peran, $blok);
                $waliBaru += $dibuat ? 1 : 0;
            } catch (GalatBarisImport $exception) {
                $galat = [...$galat, ...$exception->galat];
            }
        }

        if ($galat === []) {
            try {
                $this->terapkanUtamaDanMeninggal($pelaku, $siswa, $nilai, $dipakai);
            } catch (GalatBarisImport $exception) {
                $galat = $exception->galat;
            }
        }

        if ($galat !== []) {
            throw new GalatBarisImport($galat);
        }

        return [
            'siswa' => $siswa,
            'baru' => $baru,
            'berubah' => self::logTerakhir() !== $logAwal,
            'waliBaru' => $waliBaru,
            'waliDipakai' => array_values(array_map(fn (WaliMurid $wali): int => (int) $wali->getKey(), $dipakai)),
        ];
    }

    /**
     * @param  array<string, mixed>  $nilai
     * @return array{Siswa, bool}
     *
     * @throws GalatBarisImport
     */
    private function terapkanSiswa(User $pelaku, array $nilai): array
    {
        if ($nilai['nis'] === null) {
            throw new GalatBarisImport([['nis', 'Isi NIS: NIS adalah kunci setiap baris.']]);
        }

        $isian = [];

        foreach (self::KOLOM_SISWA as $field => $kolom) {
            if ($field !== 'username') {
                $isian[$field] = $nilai[$kolom];
            }
        }

        $siswa = Siswa::query()->with('user')->where('nis', $nilai['nis'])->first();

        if ($siswa === null) {
            return [$this->langkah(fn (): Siswa => $this->siswaService->create($pelaku, $isian), self::KOLOM_SISWA, null), true];
        }

        $data = [...self::dataSiswaTersimpan($siswa), ...array_filter($isian, fn (mixed $isi): bool => $isi !== null)];
        $this->langkah(fn (): Siswa => $this->siswaService->update($pelaku, $siswa, $data), self::KOLOM_SISWA, null);

        return [$siswa, false];
    }

    /**
     * Link a parent block to the student, and return the parent and whether
     * it was created.
     *
     * - The student's own ayah or ibu, or one of its own wali: the same
     *   person when the NIK is the same, or the name (case and spaces aside)
     *   is, or no name was given. Empty fields are filled and different ones
     *   updated. Another person as ayah or ibu is an error.
     * - Otherwise an existing parent with the same NIK and name, or the same
     *   name and phone number (and no other NIK). More than one is an error;
     *   none creates a new parent.
     *
     * @param  array{nama: string|null, no_hp: string|null, nik: string|null, pekerjaan: string|null, meninggal: bool|null}  $blok
     * @return array{WaliMurid, bool}
     *
     * @throws GalatBarisImport
     */
    private function terapkanWali(User $pelaku, Siswa $siswa, string $peran, array $blok): array
    {
        $hubungan = HubunganWali::from($peran);
        $kolomNama = "{$peran}_nama";

        $sendiri = SiswaWali::query()
            ->with('waliMurid.user')
            ->where('siswa_id', $siswa->getKey())
            ->where('hubungan', $hubungan->value)
            ->lockForUpdate()
            ->get()
            ->map(fn (SiswaWali $siswaWali): WaliMurid => $siswaWali->waliMurid);

        if ($hubungan !== HubunganWali::Wali && $sendiri->isNotEmpty()) {
            $ada = $sendiri->first();

            if (! self::orangSama($ada, $blok)) {
                throw new GalatBarisImport([[$kolomNama, "{$siswa->user->name} sudah punya {$peran} {$ada->user->name}. Ubah lewat layar Siswa."]]);
            }

            $this->perbaruiWali($pelaku, $ada, $peran, $blok);

            return [$ada, false];
        }

        $sama = $sendiri->filter(fn (WaliMurid $wali): bool => self::orangSama($wali, $blok));

        if ($sama->count() === 1) {
            $this->perbaruiWali($pelaku, $sama->first(), $peran, $blok);

            return [$sama->first(), false];
        }

        if ($blok['nama'] === null) {
            throw new GalatBarisImport([[$kolomNama, "Isi nama {$peran}."]]);
        }

        $calon = $this->calonWali($peran, $blok);

        if (count($calon) > 1) {
            throw new GalatBarisImport([[$kolomNama, 'Ada '.count($calon)." wali murid yang cocok dengan {$peran} ini. Hubungkan lewat layar Siswa."]]);
        }

        if (count($calon) === 1) {
            $wali = $calon[0];
            $this->perbaruiWali($pelaku, $wali, $peran, $blok);
            $this->langkah(
                fn (): SiswaWali => $this->siswaWaliService->tambahkan($pelaku, $siswa, ['wali' => 'ada', 'wali_murid_id' => $wali->getKey(), 'hubungan' => $peran]),
                self::kolomWali($peran),
                $kolomNama,
            );

            return [$wali->refresh(), false];
        }

        $siswaWali = $this->langkah(fn (): SiswaWali => $this->siswaWaliService->tambahkan($pelaku, $siswa, [
            'wali' => 'baru',
            'hubungan' => $peran,
            'name' => $blok['nama'],
            'no_hp' => $blok['no_hp'],
            'nik' => $blok['nik'],
            'pekerjaan' => $blok['pekerjaan'],
        ]), self::kolomWali($peran), $kolomNama);

        return [$siswaWali->waliMurid, true];
    }

    /**
     * Existing parents that are the person of $blok (see terapkanWali()).
     *
     * @param  array{nama: string|null, no_hp: string|null, nik: string|null, pekerjaan: string|null, meninggal: bool|null}  $blok
     * @return list<WaliMurid>
     *
     * @throws GalatBarisImport
     */
    private function calonWali(string $peran, array $blok): array
    {
        $nama = self::samakan((string) $blok['nama']);
        $calon = [];

        if ($blok['nik'] !== null) {
            try {
                $pemilik = User::query()->denganNik($blok['nik'])->with('waliMurid')->first();
            } catch (KunciNikTidakSiap) {
                throw new GalatBarisImport([["{$peran}_nik", UserService::PESAN_KUNCI_NIK]]);
            }

            if ($pemilik !== null) {
                if ($pemilik->waliMurid === null || self::samakan($pemilik->name) !== $nama) {
                    throw new GalatBarisImport([["{$peran}_nik", UserService::PESAN_NIK_ORANG_LAIN]]);
                }

                $calon[$pemilik->waliMurid->getKey()] = $pemilik->waliMurid->setRelation('user', $pemilik);
            }
        }

        if ($blok['no_hp'] !== null) {
            $noHp = UserService::normalisasiNoHp($blok['no_hp']);

            $sama = WaliMurid::query()
                ->with('user')
                ->whereHas('user', fn ($user) => $user->where('no_hp', $noHp))
                ->get()
                ->filter(fn (WaliMurid $wali): bool => self::samakan($wali->user->name) === $nama
                    && ($blok['nik'] === null || $wali->user->nik === null || $wali->user->nik === $blok['nik']));

            foreach ($sama as $wali) {
                $calon[$wali->getKey()] ??= $wali;
            }
        }

        return array_values($calon);
    }

    /**
     * Fill the empty and change the different fields of an existing parent
     * from its block, through WaliMuridService::ubah(). A name that differs
     * only in case or spaces is not a change. Nothing to change, no call.
     *
     * @param  array{nama: string|null, no_hp: string|null, nik: string|null, pekerjaan: string|null, meninggal: bool|null}  $blok
     *
     * @throws GalatBarisImport
     */
    private function perbaruiWali(User $pelaku, WaliMurid $wali, string $peran, array $blok): void
    {
        $user = $wali->user;
        $ubah = [];

        if ($blok['nama'] !== null && self::samakan($blok['nama']) !== self::samakan($user->name)) {
            $ubah['name'] = $blok['nama'];
        }

        if ($blok['no_hp'] !== null && UserService::normalisasiNoHp($blok['no_hp']) !== $user->no_hp) {
            $ubah['no_hp'] = $blok['no_hp'];
        }

        if ($blok['nik'] !== null && $blok['nik'] !== $user->nik) {
            $ubah['nik'] = $blok['nik'];
        }

        if ($blok['pekerjaan'] !== null && self::rapikan($blok['pekerjaan']) !== $wali->pekerjaan) {
            $ubah['pekerjaan'] = $blok['pekerjaan'];
        }

        if ($ubah === []) {
            return;
        }

        // name, email and jenis_kelamin are always written by the account
        // save, so they are sent as stored; other fields not sent keep
        // their stored value.
        $data = [
            'name' => $user->name,
            'email' => $user->email,
            'jenis_kelamin' => $user->jenis_kelamin?->value,
            'pekerjaan' => $wali->pekerjaan,
            ...$ubah,
        ];

        $this->langkah(fn (): WaliMurid => $this->waliMuridService->ubah($pelaku, $wali, $data), self::kolomWali($peran), "{$peran}_nama");
        $wali->refresh();
    }

    /**
     * @param  array<string, mixed>  $nilai
     * @param  array<string, WaliMurid>  $dipakai  the parents of this row's blocks, by peran
     *
     * @throws GalatBarisImport
     */
    private function terapkanUtamaDanMeninggal(User $pelaku, Siswa $siswa, array $nilai, array $dipakai): void
    {
        /** @var array<string, bool|null> $meninggal */
        $meninggal = ['ayah' => $nilai['ayah_meninggal'], 'ibu' => $nilai['ibu_meninggal']];

        foreach ($meninggal as $peran => $tanda) {
            if ($tanda === false && isset($dipakai[$peran]) && $dipakai[$peran]->meninggal) {
                $this->langkah(fn (): WaliMurid => $this->waliMuridService->ubahStatusMeninggal($pelaku, $dipakai[$peran], false), [], "{$peran}_meninggal");
                $dipakai[$peran]->refresh();
            }
        }

        $relasi = SiswaWali::query()
            ->with('waliMurid.user')
            ->where('siswa_id', $siswa->getKey())
            ->lockForUpdate()
            ->get();

        $akanMeninggal = function (SiswaWali $siswaWali) use ($dipakai, $meninggal): bool {
            foreach ($dipakai as $peran => $wali) {
                if ($wali->is($siswaWali->waliMurid) && ($meninggal[$peran] ?? null) !== null) {
                    return (bool) $meninggal[$peran];
                }
            }

            return $siswaWali->waliMurid->meninggal;
        };

        $target = $this->utamaYangDiminta($nilai['wali_utama'], $relasi, $dipakai);

        if ($target !== null && $akanMeninggal($target)) {
            throw new GalatBarisImport([['wali_utama', PelanggaranAturanKesiswaan::waliMeninggalTidakBolehUtama($target->waliMurid->user->name)->getMessage()]]);
        }

        if ($target === null) {
            $utamaKini = $relasi->firstWhere('utama', true);
            $target = $utamaKini !== null && ! $akanMeninggal($utamaKini)
                ? $utamaKini
                : $relasi
                    ->sortBy(fn (SiswaWali $siswaWali): string => array_search($siswaWali->hubungan->value, self::PERAN, true).'-'.str_pad((string) $siswaWali->getKey(), 12, '0', STR_PAD_LEFT))
                    ->first(fn (SiswaWali $siswaWali): bool => ! $akanMeninggal($siswaWali));
        }

        if ($target !== null && ! $target->utama) {
            $this->langkah(fn (): SiswaWali => $this->siswaWaliService->jadikanUtama($pelaku, $target), [], 'wali_utama');
        }

        foreach ($meninggal as $peran => $tanda) {
            if ($tanda === true && isset($dipakai[$peran]) && ! $dipakai[$peran]->meninggal) {
                $this->langkah(fn (): WaliMurid => $this->waliMuridService->ubahStatusMeninggal($pelaku, $dipakai[$peran], true), [], "{$peran}_meninggal");
            }
        }
    }

    /**
     * The relation named in the wali_utama cell, or null when the cell is
     * empty.
     *
     * @param  Collection<int, SiswaWali>  $relasi
     * @param  array<string, WaliMurid>  $dipakai
     *
     * @throws GalatBarisImport
     */
    private function utamaYangDiminta(?string $pilihan, Collection $relasi, array $dipakai): ?SiswaWali
    {
        if ($pilihan === null) {
            return null;
        }

        $target = isset($dipakai[$pilihan])
            ? $relasi->first(fn (SiswaWali $siswaWali): bool => $siswaWali->wali_murid_id === $dipakai[$pilihan]->getKey())
            : null;

        if ($target === null) {
            $sejenis = $relasi->filter(fn (SiswaWali $siswaWali): bool => $siswaWali->hubungan->value === $pilihan);
            $target = $sejenis->count() === 1 ? $sejenis->first() : null;
        }

        return $target ?? throw new GalatBarisImport([['wali_utama', "Isi data {$pilihan} dulu untuk menjadikannya wali utama."]]);
    }

    /**
     * Place and move the students of the rows that went through, and return
     * the moves and, per row, whether it was moved ("pindah") or placed
     * ("tempat").
     *
     * - The student must be active (the status is never changed here).
     * - For each rombel of the school year: its active students now, plus
     *   those coming in, minus those leaving, may not exceed its capacity.
     *   Every row coming into a rombel that would is an error.
     * - The rest is applied through PenempatanRombelService, each as soon as
     *   its rombel has a free seat, so students leaving a full rombel go
     *   first. What is left can never get a seat (a swap between full
     *   rombel) and is an error.
     *
     * @param  list<BarisImportSiswa>  $semuaBaris
     * @param  array<int, array{siswa: Siswa, baru: bool, berubah: bool, waliBaru: int, waliDipakai: list<int>}>  $hasilBaris
     * @return array{list<array{baris: int, nis: string, nama: string, dari: string, ke: string}>, array<int, string>}
     */
    private function terapkanRombel(User $pelaku, TahunAjaran $tahunAjaran, array $semuaBaris, array $hasilBaris): array
    {
        $rencana = [];

        foreach ($semuaBaris as $baris) {
            $rombelId = $baris->nilai['rombel'];

            if (! is_int($rombelId) || ! isset($hasilBaris[$baris->nomor])) {
                continue;
            }

            $siswa = $hasilBaris[$baris->nomor]['siswa']->refresh();

            if ($siswa->status !== StatusSiswa::Aktif) {
                $this->catat($baris->nomor, 'rombel', PelanggaranAturanAkademik::siswaTidakAktif($siswa->user->name)->getMessage());

                continue;
            }

            $dari = $this->rombelSiswa->rombelDi($siswa, (int) $tahunAjaran->getKey());

            if ($dari?->getKey() !== $rombelId) {
                $rencana[$baris->nomor] = ['siswa' => $siswa, 'ke' => $rombelId, 'dari' => $dari];
            }
        }

        if ($rencana === []) {
            return [[], []];
        }

        $rombel = Rombel::query()
            ->with('tahunAjaran')
            ->where('tahun_ajaran_id', $tahunAjaran->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        /** @var array<int, int> $isi */
        $isi = $rombel->map(fn (Rombel $item): int => $this->rombelSiswa->jumlahSiswaAktifTerkunci($item))->all();
        $akhir = $isi;

        foreach ($rencana as $langkah) {
            $akhir[$langkah['ke']]++;

            if ($langkah['dari'] !== null) {
                $akhir[$langkah['dari']->getKey()]--;
            }
        }

        foreach ($rencana as $nomor => $langkah) {
            $tujuan = $rombel[$langkah['ke']];

            if ($tujuan->kapasitas !== null && $akhir[$langkah['ke']] > $tujuan->kapasitas) {
                $this->catat($nomor, 'rombel', "Rombel {$tujuan->nama} akan berisi {$akhir[$langkah['ke']]} siswa aktif, melebihi kapasitas {$tujuan->kapasitas}. Naikkan kapasitasnya atau kurangi siswa yang masuk.");
                unset($rencana[$nomor]);
            }
        }

        $pindah = [];
        $jenis = [];

        do {
            $maju = false;

            foreach ($rencana as $nomor => $langkah) {
                $tujuan = $rombel[$langkah['ke']];

                if ($tujuan->kapasitas !== null && $isi[$langkah['ke']] >= $tujuan->kapasitas) {
                    continue;
                }

                unset($rencana[$nomor]);
                $maju = true;
                $dari = $langkah['dari'];

                try {
                    if ($dari === null) {
                        $this->penempatanRombel->tempatkan($pelaku, $langkah['siswa'], $tujuan);
                    } else {
                        $this->penempatanRombel->pindahkan($pelaku, $langkah['siswa'], $tujuan);
                    }
                } catch (PelanggaranAturanAkademik|PelanggaranAturanPengguna $exception) {
                    $this->catat($nomor, 'rombel', $exception->getMessage());

                    continue;
                }

                $isi[$langkah['ke']]++;

                if ($dari === null) {
                    $jenis[$nomor] = 'tempat';

                    continue;
                }

                $isi[$dari->getKey()]--;
                $jenis[$nomor] = 'pindah';
                $pindah[] = ['baris' => $nomor, 'nis' => $langkah['siswa']->nis, 'nama' => $langkah['siswa']->user->name, 'dari' => $dari->nama, 'ke' => $tujuan->nama];
            }
        } while ($maju && $rencana !== []);

        foreach ($rencana as $nomor => $langkah) {
            $tujuan = $rombel[$langkah['ke']];
            $kata = $langkah['dari'] === null ? 'ditempatkan' : 'dipindah';
            $this->catat($nomor, 'rombel', "{$langkah['siswa']->user->name} tidak bisa {$kata} ke {$tujuan->nama}: rombel itu penuh, dan siswa yang keluar darinya menunggu kursi di rombel lain yang juga penuh. Naikkan kapasitas {$tujuan->nama} sementara, lalu periksa lagi.");
        }

        usort($pindah, fn (array $a, array $b): int => $a['baris'] <=> $b['baris']);

        return [$pindah, $jenis];
    }

    /**
     * @param  list<BarisImportSiswa>  $semuaBaris
     */
    private function periksaNisGanda(array $semuaBaris): void
    {
        $nomorPerNis = [];

        foreach ($semuaBaris as $baris) {
            if (is_string($baris->nilai['nis'])) {
                $nomorPerNis[$baris->nilai['nis']][] = $baris->nomor;
            }
        }

        foreach ($nomorPerNis as $nomor) {
            if (count($nomor) > 1) {
                foreach ($nomor as $item) {
                    $this->catat($item, 'nis', 'NIS ini muncul lebih dari sekali di file (baris '.self::daftar($nomor).').');
                }
            }
        }
    }

    /**
     * One person (the same NIK, or the same name and phone number) named in
     * several rows, whose filled cells differ.
     *
     * @param  list<BarisImportSiswa>  $semuaBaris
     */
    private function periksaWaliBertentangan(array $semuaBaris): void
    {
        $orang = [];

        foreach ($semuaBaris as $baris) {
            foreach (self::PERAN as $peran) {
                $blok = self::blok($baris->nilai, $peran);
                $kunci = $blok === null ? null : self::kunciOrang($blok);

                if ($kunci !== null) {
                    $orang[$kunci][] = [$baris->nomor, $peran, $blok];
                }
            }
        }

        foreach ($orang as $muncul) {
            $beda = [];

            foreach (array_keys(self::ISIAN_WALI) as $isian) {
                $nilai = [];

                foreach ($muncul as [, , $blok]) {
                    if ($blok[$isian] !== null) {
                        $nilai[self::bandingkan($isian, $blok[$isian])] = true;
                    }
                }

                if (count($nilai) > 1) {
                    $beda[] = $isian;
                }
            }

            if ($beda === []) {
                continue;
            }

            $nomor = array_values(array_unique(array_column($muncul, 0)));
            sort($nomor);
            $pesan = 'Orang yang sama muncul di baris '.self::daftar($nomor).' dengan isian berbeda: '
                .implode(', ', array_map(fn (string $isian): string => self::ISIAN_WALI[$isian], $beda)).'. Samakan isiannya.';

            foreach ($muncul as [$baris, $peran, $blok]) {
                $isianTerisi = array_values(array_filter($beda, fn (string $isian): bool => $blok[$isian] !== null && $isian !== 'meninggal'))[0] ?? 'nama';
                $this->catat($baris, "{$peran}_{$isianTerisi}", $pesan);
            }
        }
    }

    /**
     * Run a service call and turn its field errors and rule violations into
     * row errors on template columns: $kolom maps the service field, any
     * other error goes to $kolomBawaan (null: the row).
     *
     * @template T
     *
     * @param  Closure(): T  $operasi
     * @param  array<string, string>  $kolom
     * @return T
     *
     * @throws GalatBarisImport
     */
    private function langkah(Closure $operasi, array $kolom, ?string $kolomBawaan): mixed
    {
        try {
            return $operasi();
        } catch (ValidationException $exception) {
            $galat = [];

            foreach ($exception->errors() as $field => $pesan) {
                foreach ((array) $pesan as $isi) {
                    $galat[] = [$kolom[$field] ?? $kolomBawaan, (string) $isi];
                }
            }

            throw new GalatBarisImport($galat);
        } catch (PelanggaranAturanKesiswaan|PelanggaranAturanAkademik|PelanggaranAturanPengguna $exception) {
            throw new GalatBarisImport([[$kolomBawaan, $exception->getMessage()]]);
        }
    }

    /**
     * @param  array<int, array{siswa: Siswa, baru: bool, berubah: bool, waliBaru: int, waliDipakai: list<int>}>  $hasilBaris
     * @param  array<int, string>  $jenisRombel
     * @return array<string, int>
     */
    private function ringkasan(array $hasilBaris, array $jenisRombel): array
    {
        $ringkasan = array_fill_keys(HasilImportSiswa::RINGKASAN, 0);
        $waliDipakai = [];

        foreach ($hasilBaris as $nomor => $hasil) {
            if (isset($this->galat[$nomor])) {
                continue;
            }

            $waliDipakai = [...$waliDipakai, ...$hasil['waliDipakai']];
            $ringkasan['wali_baru'] += $hasil['waliBaru'];
            $jenis = $jenisRombel[$nomor] ?? null;

            $ringkasan[match (true) {
                $hasil['baru'] => 'siswa_baru',
                $jenis === 'pindah' => 'siswa_dipindah',
                $hasil['berubah'] || $jenis === 'tempat' => 'siswa_diperbarui',
                default => 'siswa_tanpa_perubahan',
            }]++;
        }

        $ringkasan['wali_dipakai_bersama'] = $waliDipakai === [] ? 0 : DB::query()
            ->fromSub(SiswaWali::query()
                ->select('wali_murid_id')
                ->whereIn('wali_murid_id', array_unique($waliDipakai))
                ->groupBy('wali_murid_id')
                ->havingRaw('count(*) > 1'), 'dipakai_bersama')
            ->count();
        $ringkasan['baris_bergalat'] = count($this->galat);

        return $ringkasan;
    }

    private function catat(int $nomor, ?string $kolom, string $pesan): void
    {
        $this->galat[$nomor][] = [$kolom, $pesan];
    }

    /**
     * By row, then by template column (the row itself first).
     *
     * @return list<array{baris: int|null, kolom: string|null, pesan: string}>
     */
    private function daftarGalat(): array
    {
        $urutan = array_flip(array_keys(KolomImportSiswa::KOLOM));
        $daftar = [];

        ksort($this->galat);

        foreach ($this->galat as $nomor => $galat) {
            usort($galat, fn (array $a, array $b): int => ($a[0] === null ? -1 : $urutan[$a[0]]) <=> ($b[0] === null ? -1 : $urutan[$b[0]]));

            foreach ($galat as [$kolom, $pesan]) {
                $daftar[] = ['baris' => $nomor, 'kolom' => $kolom, 'pesan' => $pesan];
            }
        }

        return $daftar;
    }

    /**
     * The cells of one parent block, or null when all are empty.
     *
     * @param  array<string, mixed>  $nilai
     * @return array{nama: string|null, no_hp: string|null, nik: string|null, pekerjaan: string|null, meninggal: bool|null}|null
     */
    private static function blok(array $nilai, string $peran): ?array
    {
        /** @var array{nama: string|null, no_hp: string|null, nik: string|null, pekerjaan: string|null, meninggal: bool|null} $blok */
        $blok = [
            'nama' => $nilai["{$peran}_nama"] ?? null,
            'no_hp' => $nilai["{$peran}_no_hp"] ?? null,
            'nik' => $nilai["{$peran}_nik"] ?? null,
            'pekerjaan' => $nilai["{$peran}_pekerjaan"] ?? null,
            'meninggal' => $nilai["{$peran}_meninggal"] ?? null,
        ];

        return array_filter($blok, fn (mixed $isi): bool => $isi !== null) === [] ? null : $blok;
    }

    /**
     * Who a block names, to find it again in other rows: its NIK, else its
     * name and phone number; null when it has neither.
     *
     * @param  array{nama: string|null, no_hp: string|null, nik: string|null, pekerjaan: string|null, meninggal: bool|null}  $blok
     */
    private static function kunciOrang(array $blok): ?string
    {
        return match (true) {
            $blok['nik'] !== null => 'nik:'.$blok['nik'],
            $blok['no_hp'] !== null && $blok['nama'] !== null => 'hp:'.UserService::normalisasiNoHp($blok['no_hp']).'|'.self::samakan($blok['nama']),
            default => null,
        };
    }

    private static function bandingkan(string $isian, mixed $nilai): string
    {
        return match ($isian) {
            'no_hp' => UserService::normalisasiNoHp((string) $nilai),
            'meninggal' => $nilai ? 'ya' : 'tidak',
            'nik' => (string) $nilai,
            default => self::samakan((string) $nilai),
        };
    }

    /**
     * @param  array{nama: string|null, no_hp: string|null, nik: string|null, pekerjaan: string|null, meninggal: bool|null}  $blok
     */
    private static function orangSama(WaliMurid $wali, array $blok): bool
    {
        if ($blok['nik'] !== null && $wali->user->nik !== null && $blok['nik'] === $wali->user->nik) {
            return true;
        }

        return $blok['nama'] === null || self::samakan($blok['nama']) === self::samakan($wali->user->name);
    }

    /**
     * What the account save writes when a field is not sent: name, email and
     * jenis_kelamin always, and SiswaService the student fields always, so
     * all of them are sent as stored and the filled cells laid over them.
     *
     * @return array<string, mixed>
     */
    private static function dataSiswaTersimpan(Siswa $siswa): array
    {
        $user = $siswa->user;

        return [
            'name' => $user->name,
            'email' => $user->email,
            'jenis_kelamin' => $user->jenis_kelamin?->value,
            'agama' => $user->agama?->value,
            'tempat_lahir' => $user->tempat_lahir,
            'tanggal_lahir' => $user->tanggal_lahir?->toDateString(),
            'alamat' => $user->alamat,
            'no_hp' => $user->no_hp,
            'nis' => $siswa->nis,
            'nisn' => $siswa->nisn,
            'tanggal_masuk' => $siswa->tanggal_masuk?->toDateString(),
            'asal_sekolah' => $siswa->asal_sekolah,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function kolomWali(string $peran): array
    {
        return array_map(fn (string $isian): string => "{$peran}_{$isian}", self::KOLOM_WALI);
    }

    /**
     * "2 dan 4", "2, 3, dan 4".
     *
     * @param  list<int>  $nomor
     */
    private static function daftar(array $nomor): string
    {
        $terakhir = array_pop($nomor);

        return match (count($nomor)) {
            0 => (string) $terakhir,
            1 => "{$nomor[0]} dan {$terakhir}",
            default => implode(', ', $nomor).", dan {$terakhir}",
        };
    }

    /**
     * Collapse whitespace and ignore case, to compare names and jobs.
     */
    private static function samakan(string $teks): string
    {
        return mb_strtolower(self::rapikan($teks));
    }

    private static function rapikan(string $teks): string
    {
        return trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $teks));
    }

    private static function logTerakhir(): int
    {
        return (int) Activity::query()->max('id');
    }
}
