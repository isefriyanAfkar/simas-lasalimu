<?php

namespace App\Domains\Academic\Services;

use App\Domains\Academic\Enums\AksiAkademik;
use App\Domains\Academic\Enums\JenisSemester;
use App\Domains\Academic\Models\Semester;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Models\ImportSiswa;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * The only place that writes school years (and creates or deletes their
 * semesters). Semester dates and semester activation live in SemesterService.
 *
 * Rules:
 * - nama is "YYYY/YYYY", unique, and the second year is the first plus one
 * - tanggal_selesai is after tanggal_mulai; tanggal_mulai lies in the first
 *   year and tanggal_selesai in the second
 * - the ranges of two school years never overlap (touching is allowed)
 * - creating a school year creates exactly two semesters: Ganjil from its
 *   start to 31 December of the first year, Genap from 1 January of the
 *   second year to its end
 * - a date change may not leave a semester outside the new range
 * - at most one school year is active; activating one deactivates the others
 *   and activates its semester for today, or Ganjil when today is in neither,
 *   so an active school year always has exactly one active semester
 * - only an inactive school year may be deleted; its semesters go with it
 * - a school year used by rombel cannot be deleted (rombel restricts the
 *   delete in the database too); the refusal names how many use it
 */
class TahunAjaranService
{
    /**
     * Two first saves into an empty table can deadlock on the gap lock taken
     * by validate(); the retry then sees the other row and fails validation
     * instead of ending in a server error.
     */
    private const int ATTEMPTS = 3;

    /**
     * @param  array{nama: string, tanggal_mulai: string, tanggal_selesai: string}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): TahunAjaran
    {
        return DB::transaction(function () use ($data): TahunAjaran {
            $validated = $this->validate($data, null);
            $tahunPertama = $this->firstYear($validated['nama']);

            $tahunAjaran = TahunAjaran::create([...$validated, 'status_aktif' => false]);

            $tahunAjaran->semester()->create([
                'jenis' => JenisSemester::Ganjil,
                'tanggal_mulai' => $validated['tanggal_mulai'],
                'tanggal_selesai' => "{$tahunPertama}-12-31",
                'status_aktif' => false,
            ]);

            $tahunAjaran->semester()->create([
                'jenis' => JenisSemester::Genap,
                'tanggal_mulai' => ($tahunPertama + 1).'-01-01',
                'tanggal_selesai' => $validated['tanggal_selesai'],
                'status_aktif' => false,
            ]);

            return $tahunAjaran;
        }, self::ATTEMPTS);
    }

    /**
     * @param  array{nama: string, tanggal_mulai: string, tanggal_selesai: string}  $data
     *
     * @throws ValidationException
     */
    public function update(TahunAjaran $tahunAjaran, array $data): TahunAjaran
    {
        return DB::transaction(function () use ($tahunAjaran, $data): TahunAjaran {
            $validated = $this->validate($data, $tahunAjaran);

            $tahunAjaran->update($validated);

            return $tahunAjaran;
        }, self::ATTEMPTS);
    }

    public function activate(User $pelaku, TahunAjaran $tahunAjaran): TahunAjaran
    {
        return DB::transaction(function () use ($pelaku, $tahunAjaran): TahunAjaran {
            TahunAjaran::query()->lockForUpdate()->get(['id']);
            $tahunAjaran->refresh();

            if (! $tahunAjaran->status_aktif) {
                $digantikan = TahunAjaran::query()->whereKeyNot($tahunAjaran->getKey())->where('status_aktif', true)->first();

                TahunAjaran::query()->whereKeyNot($tahunAjaran->getKey())->where('status_aktif', true)->update(['status_aktif' => false]);
                $tahunAjaran->update(['status_aktif' => true]);
                AktivitasAkademik::logActivation(AksiAkademik::TahunAjaranDiaktifkan, $pelaku, $tahunAjaran, $digantikan);
            }

            if ($tahunAjaran->semester()->where('status_aktif', true)->doesntExist()) {
                $semester = $this->semesterForToday($tahunAjaran);
                $digantikan = Semester::query()->with('tahunAjaran')->where('status_aktif', true)->first();

                Semester::query()->where('status_aktif', true)->update(['status_aktif' => false]);
                $semester->update(['status_aktif' => true]);
                AktivitasAkademik::logActivation(AksiAkademik::SemesterDiaktifkan, $pelaku, $semester, $digantikan);
            }

            return $tahunAjaran;
        });
    }

    /**
     * @throws PelanggaranAturanAkademik
     */
    public function delete(TahunAjaran $tahunAjaran): void
    {
        DB::transaction(function () use ($tahunAjaran): void {
            $tahunAjaran = TahunAjaran::query()->lockForUpdate()->findOrFail($tahunAjaran->getKey());

            if ($tahunAjaran->status_aktif) {
                throw PelanggaranAturanAkademik::tahunAjaranAktifTidakBolehDihapus();
            }

            // Locking read: sees rombel committed by a save that held this
            // school year's row until now, and blocks new ones until commit.
            $jumlahRombel = $tahunAjaran->rombel()->sharedLock()->count();

            if ($jumlahRombel > 0) {
                throw PelanggaranAturanAkademik::tahunAjaranDipakaiRombel($tahunAjaran->nama, $jumlahRombel);
            }

            // An import keeps its school year (restrictOnDelete), whatever
            // its status: the import history is never deleted.
            $jumlahImport = ImportSiswa::query()->where('tahun_ajaran_id', $tahunAjaran->getKey())->sharedLock()->count();

            if ($jumlahImport > 0) {
                throw PelanggaranAturanAkademik::tahunAjaranDipakaiImport($tahunAjaran->nama, $jumlahImport);
            }

            foreach ($tahunAjaran->semester as $semester) {
                $semester->delete();
            }

            $tahunAjaran->delete();
        });
    }

    private function semesterForToday(TahunAjaran $tahunAjaran): Semester
    {
        $today = Carbon::today()->toDateString();

        return $tahunAjaran->semester()
            ->whereDate('tanggal_mulai', '<=', $today)
            ->whereDate('tanggal_selesai', '>=', $today)
            ->first()
            ?? $tahunAjaran->semester()->where('jenis', JenisSemester::Ganjil->value)->firstOrFail();
    }

    /**
     * Must run inside a transaction: the school years are locked so two
     * concurrent saves cannot both pass the overlap check.
     *
     * @param  array<string, mixed>  $data
     * @return array{nama: string, tanggal_mulai: string, tanggal_selesai: string}
     *
     * @throws ValidationException
     */
    private function validate(array $data, ?TahunAjaran $ignore): array
    {
        TahunAjaran::query()->lockForUpdate()->get(['id']);

        $validator = Validator::make($data, [
            'nama' => [
                'required',
                'string',
                'regex:/^\d{4}\/\d{4}\z/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && preg_match('/^(\d{4})\/(\d{4})\z/', $value, $years) === 1 && (int) $years[2] !== (int) $years[1] + 1) {
                        $fail('Tahun kedua pada nama harus tahun pertama ditambah satu, misalnya 2026/2027.');
                    }
                },
                Rule::unique('tahun_ajaran', 'nama')->ignore($ignore),
            ],
            'tanggal_mulai' => ['required', 'date_format:Y-m-d'],
            'tanggal_selesai' => ['required', 'date_format:Y-m-d', 'after:tanggal_mulai'],
        ], [
            'nama.regex' => 'Nama tahun ajaran harus berformat 2026/2027.',
            'nama.unique' => 'Tahun ajaran dengan nama ini sudah ada.',
            'tanggal_selesai.after' => 'Tanggal selesai harus sesudah tanggal mulai.',
        ], [
            'nama' => 'nama tahun ajaran',
            'tanggal_mulai' => 'tanggal mulai',
            'tanggal_selesai' => 'tanggal selesai',
        ]);

        $validator->after(function (ValidatorInstance $validator) use ($data, $ignore): void {
            if ($validator->errors()->hasAny(['nama', 'tanggal_mulai', 'tanggal_selesai'])) {
                return;
            }

            $tahunPertama = $this->firstYear((string) $data['nama']);
            $mulai = (string) $data['tanggal_mulai'];
            $selesai = (string) $data['tanggal_selesai'];

            if ((int) substr($mulai, 0, 4) !== $tahunPertama) {
                $validator->errors()->add('tanggal_mulai', "Tanggal mulai harus jatuh di tahun {$tahunPertama}.");
            }

            if ((int) substr($selesai, 0, 4) !== $tahunPertama + 1) {
                $validator->errors()->add('tanggal_selesai', 'Tanggal selesai harus jatuh di tahun '.($tahunPertama + 1).'.');
            }

            $bertumpang = TahunAjaran::query()
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
                ->whereDate('tanggal_mulai', '<=', $selesai)
                ->whereDate('tanggal_selesai', '>=', $mulai)
                ->first();

            if ($bertumpang !== null) {
                $field = $bertumpang->tanggal_mulai->toDateString() <= $mulai ? 'tanggal_mulai' : 'tanggal_selesai';
                $validator->errors()->add($field, "Rentang tanggal bertumpang tindih dengan tahun ajaran {$bertumpang->nama}.");
            }

            if ($ignore !== null) {
                foreach ($ignore->semester()->get() as $semester) {
                    if ($semester->tanggal_mulai->toDateString() < $mulai) {
                        $validator->errors()->add('tanggal_mulai', "Semester {$semester->jenis->label()} mulai {$semester->tanggal_mulai->toDateString()}, sebelum tanggal mulai baru. Ubah tanggal semester itu dulu.");
                    }

                    if ($semester->tanggal_selesai->toDateString() > $selesai) {
                        $validator->errors()->add('tanggal_selesai', "Semester {$semester->jenis->label()} selesai {$semester->tanggal_selesai->toDateString()}, sesudah tanggal selesai baru. Ubah tanggal semester itu dulu.");
                    }
                }
            }
        });

        /** @var array{nama: string, tanggal_mulai: string, tanggal_selesai: string} $validated */
        $validated = $validator->validate();

        return $validated;
    }

    private function firstYear(string $nama): int
    {
        return (int) substr($nama, 0, 4);
    }
}
