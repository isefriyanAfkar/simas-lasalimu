<?php

namespace App\Domains\Academic\Services;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Foundation\Services\KeunikanDatabase;
use App\Domains\Foundation\Settings\PengaturanSekolah;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * The only place that writes study groups (rombel).
 *
 * Rules:
 * - tingkat must be one the school level in Pengaturan Sekolah teaches
 *   (SD 1-6, SMP 7-9, SMA/SMK 10-12); with no school level set, no rombel can
 *   be created. The level is checked on create, and on update only when
 *   tingkat or the school year changes, so an old rombel stays editable after
 *   the school level is changed
 * - nomor is a whole number from 1 to 99
 * - kapasitas is a whole number from 1 to 99, or empty
 * - school year, tingkat and nomor together are unique
 * - a rombel may belong to a school year that is not active yet
 * - a rombel with placements keeps its school year, its capacity may not
 *   drop below its active students, and it cannot be deleted
 *
 * Uniqueness is checked inside a transaction that first locks the rombel
 * rows; KeunikanDatabase turns a unique index violation that still happens
 * into a field error.
 */
class RombelService
{
    public const string PESAN_JENJANG_KOSONG = 'Jenjang sekolah belum diisi. Isi dulu di menu Pengaturan Sekolah.';

    public function __construct(private RombelSiswa $rombelSiswa) {}

    /**
     * Two first saves into an empty table can deadlock on the gap lock; the
     * retry then sees the other row and fails validation.
     */
    private const int ATTEMPTS = 3;

    /**
     * @var array<string, array{string, string}>
     */
    private const array INDEKS_UNIK = [
        'rombel_tahun_ajaran_id_tingkat_nomor_unique' => ['nomor', 'Rombel ini baru saja dibuat pengguna lain. Periksa daftar, lalu simpan lagi.'],
    ];

    /**
     * @param  array{tahun_ajaran_id: int|string, tingkat: int|string, nomor: int|string, kapasitas?: int|string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): Rombel
    {
        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): Rombel => DB::transaction(
            fn (): Rombel => Rombel::create($this->validate($data, null)),
            self::ATTEMPTS,
        ));
    }

    /**
     * @param  array{tahun_ajaran_id: int|string, tingkat: int|string, nomor: int|string, kapasitas?: int|string|null}  $data
     *
     * @throws ValidationException
     */
    public function update(Rombel $rombel, array $data): Rombel
    {
        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): Rombel => DB::transaction(function () use ($rombel, $data): Rombel {
            $rombel->update($this->validate($data, $rombel));

            return $rombel;
        }, self::ATTEMPTS));
    }

    /**
     * Refuses a rombel that holds placements, students who left included
     * (their placement is history). Every table that later references rombel
     * (schedules, journals) uses restrictOnDelete and is counted here the
     * same way, inside the transaction and under a lock, with a message
     * naming it.
     *
     * @throws PelanggaranAturanAkademik
     */
    public function delete(Rombel $rombel): void
    {
        DB::transaction(function () use ($rombel): void {
            $terkunci = Rombel::query()->lockForUpdate()->findOrFail($rombel->getKey());

            // Locking read: sees placements committed by a write that held
            // this rombel's row until now, and blocks new ones until commit.
            $jumlahSiswa = $terkunci->penempatan()->sharedLock()->count();

            if ($jumlahSiswa > 0) {
                throw PelanggaranAturanAkademik::rombelDipakaiSiswa($terkunci->nama, $jumlahSiswa);
            }

            $terkunci->delete();
        });
    }

    /**
     * Locks the chosen school year row (shared), so a concurrent
     * TahunAjaranService::delete() either finishes first (and the exists rule
     * then fails cleanly) or waits until this rombel is saved (and then counts
     * it and refuses).
     */
    private function lockSchoolYear(mixed $tahunAjaranId): void
    {
        if (is_numeric($tahunAjaranId)) {
            TahunAjaran::query()->whereKey((int) $tahunAjaranId)->sharedLock()->first(['id']);
        }
    }

    /**
     * Must run inside a transaction: the rombel rows are locked first so two
     * concurrent saves cannot both pass the unique check.
     *
     * @param  array<string, mixed>  $data
     * @return array{tahun_ajaran_id: int, tingkat: Tingkat, nomor: int, kapasitas: int|null}
     *
     * @throws ValidationException
     */
    private function validate(array $data, ?Rombel $ignore): array
    {
        if ($ignore === null && app(PengaturanSekolah::class)->jenjang === null) {
            throw ValidationException::withMessages(['tingkat' => self::PESAN_JENJANG_KOSONG]);
        }

        $this->lockSchoolYear($data['tahun_ajaran_id'] ?? null);
        Rombel::query()->lockForUpdate()->get(['id']);

        $validator = Validator::make($data, [
            'tahun_ajaran_id' => ['required', 'integer', Rule::exists('tahun_ajaran', 'id')],
            'tingkat' => ['required', 'integer', 'between:1,12'],
            'nomor' => ['required', 'integer', 'between:1,99'],
            'kapasitas' => ['nullable', 'integer', 'between:1,99'],
        ], [], [
            'tahun_ajaran_id' => 'tahun ajaran',
            'tingkat' => 'tingkat',
            'nomor' => 'nomor',
            'kapasitas' => 'kapasitas',
        ]);

        $validator->after(function (ValidatorInstance $validator) use ($data, $ignore): void {
            if ($validator->errors()->hasAny(['tahun_ajaran_id', 'tingkat', 'nomor'])) {
                return;
            }

            $tahunAjaranId = (int) $data['tahun_ajaran_id'];
            $tingkat = Tingkat::from((int) $data['tingkat']);
            $nomor = (int) $data['nomor'];

            $this->checkSchoolLevel($validator, $tingkat, $tahunAjaranId, $ignore);
            $this->checkUnique($validator, $tahunAjaranId, $tingkat, $nomor, $ignore);
        });

        if ($ignore !== null) {
            $validator->after(fn (ValidatorInstance $validator) => $this->checkMembers($validator, $data, $ignore));
        }

        $validated = $validator->validate();
        $kapasitas = $validated['kapasitas'] ?? null;

        return [
            'tahun_ajaran_id' => (int) $validated['tahun_ajaran_id'],
            'tingkat' => Tingkat::from((int) $validated['tingkat']),
            'nomor' => (int) $validated['nomor'],
            'kapasitas' => $kapasitas === null || $kapasitas === '' ? null : (int) $kapasitas,
        ];
    }

    /**
     * A rombel with placements keeps its school year (the placements copy
     * it; a foreign key on both columns refuses the change too), and its
     * capacity may not drop below its active students. Runs with the rombel
     * rows locked, and every placement locks its rombel row first. Both
     * counts are locking reads, so they see the latest committed rows
     * whatever the transaction read before (see
     * RombelSiswa::jumlahSiswaAktifTerkunci()).
     *
     * @param  array<string, mixed>  $data
     */
    private function checkMembers(ValidatorInstance $validator, array $data, Rombel $rombel): void
    {
        $tahunAjaranId = $data['tahun_ajaran_id'] ?? null;

        if (! $validator->errors()->has('tahun_ajaran_id') && is_numeric($tahunAjaranId) && (int) $tahunAjaranId !== $rombel->tahun_ajaran_id) {
            $jumlahSiswa = $rombel->penempatan()->sharedLock()->count();

            if ($jumlahSiswa > 0) {
                $validator->errors()->add('tahun_ajaran_id', "Tahun ajaran rombel ini tidak bisa diubah karena sudah punya {$jumlahSiswa} siswa.");
            }
        }

        $kapasitas = $data['kapasitas'] ?? null;

        if (! $validator->errors()->has('kapasitas') && is_numeric($kapasitas)) {
            $jumlahAktif = $this->rombelSiswa->jumlahSiswaAktifTerkunci($rombel);

            if ((int) $kapasitas < $jumlahAktif) {
                $validator->errors()->add('kapasitas', "Kapasitas tidak boleh kurang dari jumlah siswa aktif di rombel ini ({$jumlahAktif}).");
            }
        }
    }

    /**
     * Skipped for an existing rombel whose tingkat and school year stay the
     * same, so it stays editable after the school level changes.
     */
    private function checkSchoolLevel(ValidatorInstance $validator, Tingkat $tingkat, int $tahunAjaranId, ?Rombel $ignore): void
    {
        if ($ignore !== null && $ignore->tingkat === $tingkat && $ignore->tahun_ajaran_id === $tahunAjaranId) {
            return;
        }

        $jenjang = app(PengaturanSekolah::class)->jenjang;

        if ($jenjang === null) {
            $validator->errors()->add('tingkat', self::PESAN_JENJANG_KOSONG);

            return;
        }

        if (! in_array($tingkat, Tingkat::untukJenjang($jenjang), true)) {
            $pilihan = Tingkat::untukJenjang($jenjang);
            $validator->errors()->add('tingkat', sprintf(
                'Tingkat %s tidak ada di jenjang %s. Pilih tingkat %s sampai %s.',
                $tingkat->label(),
                $jenjang->value,
                $pilihan[0]->label(),
                $pilihan[count($pilihan) - 1]->label(),
            ));
        }
    }

    private function checkUnique(ValidatorInstance $validator, int $tahunAjaranId, Tingkat $tingkat, int $nomor, ?Rombel $ignore): void
    {
        $kembar = Rombel::query()
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('tingkat', $tingkat->value)
            ->where('nomor', $nomor)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore?->getKey()))
            ->exists();

        if ($kembar) {
            $tahunAjaran = TahunAjaran::query()->findOrFail($tahunAjaranId);
            $validator->errors()->add('nomor', 'Rombel '.Rombel::namaDari($tingkat, $nomor)." sudah ada di tahun ajaran {$tahunAjaran->nama}.");
        }
    }
}
