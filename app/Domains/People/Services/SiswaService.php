<?php

namespace App\Domains\People\Services;

use App\Domains\Academic\Services\PelanggaranAturanAkademik;
use App\Domains\Academic\Services\PenempatanRombelService;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\KeunikanDatabase;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Enums\AksiKesiswaan;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Models\Siswa;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * The only place that writes students. One form holds the account, the
 * biodata and the student fields; create() and update() save them in one
 * transaction and report the errors of all of them at once.
 *
 * Account and biodata follow UserService (validasiAkunSiswa,
 * simpanAkunSiswa): the same name, email and biodata rules, NIK protection
 * included, with jenis_kelamin required.
 * - every student gets a new account in the same transaction: active, with
 *   only the siswa role (no admin panel access) and a random password that
 *   is never shown
 * - the username is always the NIS; a username in $data is ignored, and
 *   changing the NIS changes the username. A NIS that is the username of
 *   another account is rejected on the nis field
 *
 * Student fields:
 * - nis is required, 4 to 20 digits, unique
 * - nisn is 10 digits, unique when filled
 * - "sudah dipakai" names the holder
 * - tanggal_masuk is a Y-m-d date, not after today, or empty
 * - asal_sekolah is at most 150 characters, inner whitespace collapsed
 *
 * Status: a new student is aktif; create() and update() never change the
 * status. ubahStatus() moves aktif to lulus, mutasi_keluar or keluar with a
 * date (not after today) and a note, and deactivates the account.
 * aktifkanKembali() clears them and reactivates the account only when it is
 * still inactive because of that status change; it refuses while a rombel
 * the student is placed in (active or later school year) is full. Both are logged explicitly
 * (log "kesiswaan"). Students are never deleted.
 *
 * An actor may change a student only when UserService::canManage allows
 * managing its account.
 */
class SiswaService
{
    public const string LOG_NAME = 'kesiswaan';

    private const int ATTEMPTS = 3;

    /**
     * @var array<string, array{string, string}>
     */
    private const array INDEKS_UNIK = [
        'siswa_nis_unique' => ['nis', 'NIS ini baru saja dipakai siswa lain. Periksa lagi, lalu simpan.'],
        'siswa_nisn_unique' => ['nisn', 'NISN ini baru saja dipakai siswa lain. Periksa lagi, lalu simpan.'],
        'siswa_user_id_unique' => ['nis', 'Akun ini baru saja didaftarkan sebagai siswa. Periksa daftar, lalu simpan lagi.'],
    ];

    /**
     * @var array<string, string>
     */
    private const array LABEL_UNIK = [
        'nis' => 'NIS',
        'nisn' => 'NISN',
    ];

    public function __construct(
        private UserService $userService,
        private PenempatanRombelService $penempatanRombel,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the account and biodata fields (name, email, nik, ...) and the student fields
     *
     * @throws ValidationException
     */
    public function create(User $pelaku, array $data): Siswa
    {
        return self::usernameErrorsOnNis(fn (): Siswa => KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): Siswa => DB::transaction(function () use ($pelaku, $data): Siswa {
            $this->lockStudentRows();

            [$siswaData, $akunData] = $this->validateAll($data, null);
            $user = $this->userService->simpanAkunSiswa($pelaku, null, $akunData);

            return app(CauserResolver::class)->withCauser($pelaku, fn (): Siswa => Siswa::create([
                ...$siswaData,
                'user_id' => $user->getKey(),
                'status' => StatusSiswa::Aktif,
            ]));
        }, self::ATTEMPTS)));
    }

    /**
     * The status and the exit fields are not taken from $data.
     *
     * @param  array<string, mixed>  $data  the account and biodata fields (with hapus_nik) and the student fields
     *
     * @throws ValidationException
     */
    public function update(User $pelaku, Siswa $siswa, array $data): Siswa
    {
        $this->ensureCanManage($pelaku, $siswa);

        return self::usernameErrorsOnNis(fn (): Siswa => KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): Siswa => DB::transaction(function () use ($pelaku, $siswa, $data): Siswa {
            $this->lockStudentRows();
            $siswa->refresh();

            [$siswaData, $akunData] = $this->validateAll($data, $siswa);
            $this->userService->simpanAkunSiswa($pelaku, $siswa->user, $akunData);

            app(CauserResolver::class)->withCauser($pelaku, fn (): bool => $siswa->update($siswaData));

            return $siswa;
        }, self::ATTEMPTS)));
    }

    /**
     * Leave with lulus, mutasi_keluar or keluar.
     *
     * @param  array{status?: mixed, tanggal_keluar?: mixed, keterangan_keluar?: mixed}  $data
     *
     * @throws ValidationException
     */
    public function ubahStatus(User $pelaku, Siswa $siswa, array $data): Siswa
    {
        $this->ensureCanManage($pelaku, $siswa);

        return DB::transaction(function () use ($pelaku, $siswa, $data): Siswa {
            $terkunci = $this->lockStudentRow($siswa);

            if ($terkunci->status !== StatusSiswa::Aktif) {
                throw PelanggaranAturanKesiswaan::sudahTidakAktif();
            }

            if (is_string($data['keterangan_keluar'] ?? null)) {
                $data['keterangan_keluar'] = self::normalizeSpaces($data['keterangan_keluar']);
            }

            /** @var array{status: string, tanggal_keluar: string, keterangan_keluar: string} $validated */
            $validated = Validator::make($data, [
                'status' => ['required', 'string', Rule::in(array_keys(StatusSiswa::opsiKeluar()))],
                'tanggal_keluar' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'keterangan_keluar' => ['required', 'string', 'max:500'],
            ], [
                'tanggal_keluar.before_or_equal' => 'Tanggal keluar tidak boleh setelah hari ini.',
            ], [
                'status' => 'status',
                'tanggal_keluar' => 'tanggal keluar',
                'keterangan_keluar' => 'keterangan',
            ])->validate();

            $terkunci->update([
                'status' => StatusSiswa::from($validated['status']),
                'tanggal_keluar' => $validated['tanggal_keluar'],
                'keterangan_keluar' => $validated['keterangan_keluar'],
                'log_penonaktifan_akun_id' => $this->userService->nonaktifkanKarenaStatusKeluar($pelaku, $terkunci->user),
            ]);

            $this->log(AksiKesiswaan::SiswaStatusDiubah, $pelaku, $terkunci, [
                'attributes' => [
                    'status' => $validated['status'],
                    'tanggal_keluar' => $validated['tanggal_keluar'],
                    'keterangan_keluar' => $validated['keterangan_keluar'],
                ],
                'old' => ['status' => StatusSiswa::Aktif->value],
            ]);

            return $siswa->refresh();
        });
    }

    /**
     * Come back to aktif. The student counts against the capacity again, so
     * every rombel the student is placed in for the active or a later school
     * year must have room (PenempatanRombelService locks it and refuses when
     * it is full). The account is reactivated only when it is still inactive
     * because of the deactivation done by ubahStatus(); see
     * UserService::aktifkanKembaliSetelahStatusKeluar().
     *
     * @throws PelanggaranAturanKesiswaan
     * @throws PelanggaranAturanAkademik
     */
    public function aktifkanKembali(User $pelaku, Siswa $siswa): Siswa
    {
        $this->ensureCanManage($pelaku, $siswa);

        return DB::transaction(function () use ($pelaku, $siswa): Siswa {
            $terkunci = $this->lockStudentRow($siswa);

            if ($terkunci->status === StatusSiswa::Aktif) {
                throw PelanggaranAturanKesiswaan::sudahAktif();
            }

            $this->penempatanRombel->pastikanMuatUntukAktifKembali($terkunci);

            $lama = [
                'status' => $terkunci->status->value,
                'tanggal_keluar' => $terkunci->tanggal_keluar?->toDateString(),
                'keterangan_keluar' => $terkunci->keterangan_keluar,
            ];
            $logPenonaktifan = $terkunci->log_penonaktifan_akun_id;

            $terkunci->update([
                'status' => StatusSiswa::Aktif,
                'tanggal_keluar' => null,
                'keterangan_keluar' => null,
                'log_penonaktifan_akun_id' => null,
            ]);

            if ($logPenonaktifan !== null) {
                $this->userService->aktifkanKembaliSetelahStatusKeluar($pelaku, $terkunci->user, $logPenonaktifan);
            }

            $this->log(AksiKesiswaan::SiswaDiaktifkanKembali, $pelaku, $terkunci, [
                'attributes' => ['status' => StatusSiswa::Aktif->value, 'tanggal_keluar' => null, 'keterangan_keluar' => null],
                'old' => $lama,
            ]);

            return $siswa->refresh();
        });
    }

    /**
     * Validate the account and the student fields together, so every error
     * is reported at once. The username sent to UserService is the NIS. Must
     * run inside a transaction that locked the student rows first.
     *
     * @param  array<string, mixed>  $data
     * @return array{array{nis: string, nisn: string|null, tanggal_masuk: string|null, asal_sekolah: string|null}, array<string, mixed>}
     *
     * @throws ValidationException
     */
    private function validateAll(array $data, ?Siswa $ignore): array
    {
        $nis = is_string($data['nis'] ?? null) ? self::normalizeSpaces($data['nis']) : ($data['nis'] ?? null);
        $akunData = [...Arr::except($data, ['username']), 'username' => is_string($nis) ? $nis : ''];

        $galatAkun = self::errorsOf(fn () => $this->userService->validasiAkunSiswa($akunData, $ignore?->user));

        $siswaData = null;
        $galatSiswa = self::errorsOf(function () use ($data, $ignore, &$siswaData): void {
            $siswaData = $this->validateStudentFields($data, $ignore);
        });

        $galat = self::moveUsernameToNis([...$galatAkun, ...$galatSiswa], $galatSiswa['nis'] ?? null);

        if ($galat !== [] || $siswaData === null) {
            throw ValidationException::withMessages($galat);
        }

        return [$siswaData, [...$akunData, 'username' => $siswaData['nis']]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{nis: string, nisn: string|null, tanggal_masuk: string|null, asal_sekolah: string|null}
     *
     * @throws ValidationException
     */
    private function validateStudentFields(array $data, ?Siswa $ignore): array
    {
        $input = [];

        foreach (['nis', 'nisn', 'tanggal_masuk', 'asal_sekolah'] as $field) {
            $value = $data[$field] ?? null;

            if (is_string($value)) {
                $value = self::normalizeSpaces($value);
                $value = $value === '' ? null : $value;
            }

            $input[$field] = $value;
        }

        /** @var array{nis: string, nisn: string|null, tanggal_masuk: string|null, asal_sekolah: string|null} $validated */
        $validated = Validator::make($input, [
            'nis' => ['bail', 'required', 'string', 'regex:/^\d{4,20}\z/', $this->notTakenBy('nis', $ignore), $this->notAnotherUsername($ignore)],
            'nisn' => ['bail', 'nullable', 'string', 'regex:/^\d{10}\z/', $this->notTakenBy('nisn', $ignore)],
            'tanggal_masuk' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'asal_sekolah' => ['nullable', 'string', 'max:150'],
        ], [
            'nis.regex' => 'NIS hanya berisi angka, 4 sampai 20 digit.',
            'nisn.regex' => 'NISN harus terdiri dari tepat 10 digit angka.',
            'tanggal_masuk.before_or_equal' => 'Tanggal masuk tidak boleh setelah hari ini.',
        ], [
            'nis' => 'NIS',
            'nisn' => 'NISN',
            'tanggal_masuk' => 'tanggal masuk',
            'asal_sekolah' => 'asal sekolah',
        ])->validate();

        return [
            'nis' => $validated['nis'],
            'nisn' => $validated['nisn'],
            'tanggal_masuk' => $validated['tanggal_masuk'],
            'asal_sekolah' => $validated['asal_sekolah'],
        ];
    }

    /**
     * Unique rule whose message names the student holding the value.
     */
    private function notTakenBy(string $kolom, ?Siswa $ignore): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($kolom, $ignore): void {
            $pemilik = Siswa::query()
                ->with('user')
                ->where($kolom, $value)
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore?->getKey()))
                ->first();

            if ($pemilik !== null) {
                $fail(self::LABEL_UNIK[$kolom]." ini sudah dipakai oleh {$pemilik->user->name}.");
            }
        };
    }

    /**
     * The NIS becomes the username, so it may not be the username of another
     * account (a staff member, an operator).
     */
    private function notAnotherUsername(?Siswa $ignore): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
            $pemilik = User::query()
                ->where('username', User::normalizeUsername((string) $value))
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore?->user_id))
                ->first();

            if ($pemilik !== null) {
                $fail("NIS ini sudah dipakai sebagai nama pengguna oleh {$pemilik->name}.");
            }
        };
    }

    /**
     * Must run inside a transaction, before the unique checks.
     */
    private function lockStudentRows(): void
    {
        Siswa::query()->lockForUpdate()->get(['id']);
    }

    private function lockStudentRow(Siswa $siswa): Siswa
    {
        return Siswa::query()->with('user')->lockForUpdate()->findOrFail($siswa->getKey());
    }

    private function ensureCanManage(User $pelaku, Siswa $siswa): void
    {
        if (! $this->userService->canManage($pelaku, $siswa->user)) {
            throw PelanggaranAturanPengguna::tidakBolehMengelola();
        }
    }

    /**
     * @param  array{attributes: array<string, mixed>, old: array<string, mixed>}  $changes
     */
    private function log(AksiKesiswaan $aksi, User $pelaku, Siswa $siswa, array $changes): void
    {
        activity(self::LOG_NAME)
            ->causedBy($pelaku)
            ->performedOn($siswa)
            ->event($aksi->value)
            ->withChanges($changes)
            ->log($aksi->label());
    }

    /**
     * The username is the NIS and is not on the form: a username error
     * belongs to the nis field. When nis already has its own error, that one
     * says it better and the username error is dropped.
     *
     * @param  array<string, list<string>>  $galat
     * @param  list<string>|null  $galatNis
     * @return array<string, list<string>>
     */
    private static function moveUsernameToNis(array $galat, ?array $galatNis): array
    {
        if (! array_key_exists('username', $galat)) {
            return $galat;
        }

        $galat['nis'] = $galatNis ?? $galat['username'];
        unset($galat['username']);

        return $galat;
    }

    /**
     * Run $operasi and move a username error (also one raised by the unique
     * index of users when two saves race) to the nis field.
     *
     * @template T
     *
     * @param  Closure(): T  $operasi
     * @return T
     *
     * @throws ValidationException
     */
    private static function usernameErrorsOnNis(Closure $operasi): mixed
    {
        try {
            return $operasi();
        } catch (ValidationException $exception) {
            /** @var array<string, list<string>> $errors */
            $errors = $exception->errors();

            if (! array_key_exists('username', $errors)) {
                throw $exception;
            }

            throw ValidationException::withMessages(self::moveUsernameToNis($errors, $errors['nis'] ?? null));
        }
    }

    /**
     * The field errors $operasi throws, or none.
     *
     * @return array<string, list<string>>
     */
    private static function errorsOf(Closure $operasi): array
    {
        try {
            $operasi();
        } catch (ValidationException $exception) {
            /** @var array<string, list<string>> $errors */
            $errors = $exception->errors();

            return $errors;
        }

        return [];
    }

    /**
     * Collapse every run of whitespace, including Unicode spaces such as a
     * non-breaking space, into one ASCII space, and trim the ends.
     */
    private static function normalizeSpaces(string $value): string
    {
        return trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $value));
    }
}
