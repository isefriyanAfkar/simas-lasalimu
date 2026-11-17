<?php

namespace App\Domains\People\Services;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\KeunikanDatabase;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Enums\AksiKepegawaian;
use App\Domains\People\Enums\JenisPtk;
use App\Domains\People\Enums\StatusPtk;
use App\Domains\People\Models\Ptk;
use App\Domains\People\Models\StatusKepegawaian;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * The only place that writes staff (PTK). One form holds the account, the
 * biodata and the staff fields; create() and update() save them in one
 * transaction and report the errors of all three at once.
 *
 * Account and biodata follow UserService (validasiAkunPtk, simpanAkunPtk):
 * the same name, username, email and biodata rules, NIK protection
 * included, with jenis_kelamin required.
 * - akun "baru" creates an active account without a panel role and with a
 *   random password that is never shown
 * - akun "ada" links an existing account that has no staff record yet and
 *   that the actor may manage (UserService::canManage), e.g. an operator who
 *   also teaches; its panel role stays
 * - the account gets the staff role of jenis_ptk (guru or tendik); changing
 *   jenis_ptk swaps it; other roles are never taken away
 *
 * Staff fields:
 * - nip is 18 digits and nuptk 16 digits, unique when filled
 * - kode is trimmed and uppercased, 1 to 10 letters and digits, unique when
 *   filled; "sudah dipakai" names the holder's full name
 * - gelar_depan (max 50) and gelar_belakang (max 100) are optional, inner
 *   whitespace collapsed
 * - status_kepegawaian_id must be active, except the status the staff
 *   member already holds
 * - tahun_sertifikasi is 4 digits, not after this year, and only filled
 *   when sertifikasi is true
 *
 * Status: a new staff member is aktif; create() and update() never change
 * the status. ubahStatus() moves aktif to mutasi, pensiun or berhenti with a
 * date (not after today) and a note, and deactivates the account unless it
 * holds a panel role. aktifkanKembali() clears them and reactivates the
 * account only when it is still inactive because of that status change.
 * Both are logged explicitly (log "kepegawaian"). Staff are never deleted.
 *
 * An actor may change, change the status of, or link a staff record only
 * when UserService::canManage allows managing its account.
 */
class PtkService
{
    private const int ATTEMPTS = 3;

    /**
     * @var array<string, array{string, string}>
     */
    private const array INDEKS_UNIK = [
        'ptk_nip_unique' => ['nip', 'NIP ini baru saja dipakai guru/staf lain. Periksa lagi, lalu simpan.'],
        'ptk_nuptk_unique' => ['nuptk', 'NUPTK ini baru saja dipakai guru/staf lain. Periksa lagi, lalu simpan.'],
        'ptk_kode_unique' => ['kode', 'Kode ini baru saja dipakai guru/staf lain. Periksa lagi, lalu simpan.'],
        'ptk_user_id_unique' => ['user_id', 'Pengguna ini baru saja didaftarkan sebagai guru/staf. Periksa daftar, lalu simpan lagi.'],
    ];

    /**
     * @var array<string, string>
     */
    private const array LABEL_UNIK = [
        'nip' => 'NIP',
        'nuptk' => 'NUPTK',
        'kode' => 'Kode',
    ];

    public function __construct(private UserService $userService) {}

    /**
     * @param  array<string, mixed>  $data  akun ("baru" or "ada"), user_id, the account and biodata fields, and the staff fields
     *
     * @throws ValidationException
     */
    public function create(User $pelaku, array $data): Ptk
    {
        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): Ptk => DB::transaction(function () use ($pelaku, $data): Ptk {
            $this->lockStaffRows();

            $galat = [];
            $akun = null;
            $mode = $data['akun'] ?? null;

            if (! in_array($mode, ['baru', 'ada'], true)) {
                $galat['akun'] = ['Pilih akun baru atau pengguna yang sudah ada.'];
            }

            if ($mode === 'ada') {
                [$akun, $pesan] = $this->accountToLink($pelaku, $data['user_id'] ?? null);

                if ($pesan !== null) {
                    $galat['user_id'] = [$pesan];
                }
            }

            if ($mode !== 'ada' || $akun !== null) {
                $galat = [...$galat, ...self::errorsOf(fn () => $this->userService->validasiAkunPtk($data, $akun))];
            }

            $ptkData = null;
            $galat = [...$galat, ...self::errorsOf(function () use ($data, &$ptkData): void {
                $ptkData = $this->validateStaffFields($data, null);
            })];

            if ($galat !== [] || $ptkData === null) {
                throw ValidationException::withMessages($galat);
            }

            $jenis = JenisPtk::from($ptkData['jenis_ptk']);
            $user = $this->userService->simpanAkunPtk($pelaku, $akun, $data, $jenis->peran());

            return app(CauserResolver::class)->withCauser($pelaku, fn (): Ptk => Ptk::create([
                ...$ptkData,
                'user_id' => $user->getKey(),
                'status' => StatusPtk::Aktif,
            ]));
        }, self::ATTEMPTS));
    }

    /**
     * The status and the exit fields are not taken from $data.
     *
     * @param  array<string, mixed>  $data  the account and biodata fields (with hapus_nik) and the staff fields
     *
     * @throws ValidationException
     */
    public function update(User $pelaku, Ptk $ptk, array $data): Ptk
    {
        $this->ensureCanManage($pelaku, $ptk);

        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): Ptk => DB::transaction(function () use ($pelaku, $ptk, $data): Ptk {
            $this->lockStaffRows();
            $ptk->refresh();

            $galat = self::errorsOf(fn () => $this->userService->validasiAkunPtk($data, $ptk->user));

            $ptkData = null;
            $galat = [...$galat, ...self::errorsOf(function () use ($data, $ptk, &$ptkData): void {
                $ptkData = $this->validateStaffFields($data, $ptk);
            })];

            if ($galat !== [] || $ptkData === null) {
                throw ValidationException::withMessages($galat);
            }

            $jenis = JenisPtk::from($ptkData['jenis_ptk']);
            $this->userService->simpanAkunPtk($pelaku, $ptk->user, $data, $jenis->peran());

            app(CauserResolver::class)->withCauser($pelaku, fn (): bool => $ptk->update($ptkData));

            return $ptk;
        }, self::ATTEMPTS));
    }

    /**
     * Leave with mutasi, pensiun or berhenti.
     *
     * @param  array{status?: mixed, tanggal_keluar?: mixed, keterangan_keluar?: mixed}  $data
     *
     * @throws ValidationException
     */
    public function ubahStatus(User $pelaku, Ptk $ptk, array $data): Ptk
    {
        $this->ensureCanManage($pelaku, $ptk);

        return DB::transaction(function () use ($pelaku, $ptk, $data): Ptk {
            $terkunci = $this->lockStaffRow($ptk);

            if ($terkunci->status !== StatusPtk::Aktif) {
                throw PelanggaranAturanKepegawaian::sudahTidakAktif();
            }

            if (is_string($data['keterangan_keluar'] ?? null)) {
                $data['keterangan_keluar'] = self::normalizeSpaces($data['keterangan_keluar']);
            }

            /** @var array{status: string, tanggal_keluar: string, keterangan_keluar: string} $validated */
            $validated = Validator::make($data, [
                'status' => ['required', 'string', Rule::in(array_keys(StatusPtk::opsiKeluar()))],
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
                'status' => StatusPtk::from($validated['status']),
                'tanggal_keluar' => $validated['tanggal_keluar'],
                'keterangan_keluar' => $validated['keterangan_keluar'],
                'log_penonaktifan_akun_id' => $this->userService->nonaktifkanKarenaStatusPtk($pelaku, $terkunci->user),
            ]);

            $this->log(AksiKepegawaian::PtkStatusDiubah, $pelaku, $terkunci, [
                'attributes' => [
                    'status' => $validated['status'],
                    'tanggal_keluar' => $validated['tanggal_keluar'],
                    'keterangan_keluar' => $validated['keterangan_keluar'],
                ],
                'old' => ['status' => StatusPtk::Aktif->value],
            ]);

            return $ptk->refresh();
        });
    }

    /**
     * Come back to aktif. The account is reactivated only when it is still
     * inactive because of the deactivation done by ubahStatus(); see
     * UserService::aktifkanKembaliSetelahStatusPtk().
     */
    public function aktifkanKembali(User $pelaku, Ptk $ptk): Ptk
    {
        $this->ensureCanManage($pelaku, $ptk);

        return DB::transaction(function () use ($pelaku, $ptk): Ptk {
            $terkunci = $this->lockStaffRow($ptk);

            if ($terkunci->status === StatusPtk::Aktif) {
                throw PelanggaranAturanKepegawaian::sudahAktif();
            }

            $lama = [
                'status' => $terkunci->status->value,
                'tanggal_keluar' => $terkunci->tanggal_keluar?->toDateString(),
                'keterangan_keluar' => $terkunci->keterangan_keluar,
            ];
            $logPenonaktifan = $terkunci->log_penonaktifan_akun_id;

            $terkunci->update([
                'status' => StatusPtk::Aktif,
                'tanggal_keluar' => null,
                'keterangan_keluar' => null,
                'log_penonaktifan_akun_id' => null,
            ]);

            if ($logPenonaktifan !== null) {
                $this->userService->aktifkanKembaliSetelahStatusPtk($pelaku, $terkunci->user, $logPenonaktifan);
            }

            $this->log(AksiKepegawaian::PtkDiaktifkanKembali, $pelaku, $terkunci, [
                'attributes' => ['status' => StatusPtk::Aktif->value, 'tanggal_keluar' => null, 'keterangan_keluar' => null],
                'old' => $lama,
            ]);

            return $ptk->refresh();
        });
    }

    /**
     * The account to link in mode "ada", or the reason it cannot be linked.
     *
     * @return array{User|null, string|null}
     */
    private function accountToLink(User $pelaku, mixed $userId): array
    {
        if (! is_int($userId) && ! (is_string($userId) && ctype_digit($userId))) {
            return [null, 'Pilih pengguna yang akan dihubungkan.'];
        }

        $user = User::query()->lockForUpdate()->find((int) $userId);

        if ($user === null) {
            return [null, 'Pengguna tidak ditemukan.'];
        }

        $ptkLain = Ptk::query()->with('user')->where('user_id', $user->getKey())->first();

        if ($ptkLain !== null) {
            return [null, "Pengguna ini sudah terdaftar sebagai guru/staf: {$ptkLain->nama_lengkap}."];
        }

        if (! $this->userService->canManage($pelaku, $user)) {
            return [null, 'Anda tidak boleh menghubungkan pengguna ini. Minta super admin untuk menghubungkannya.'];
        }

        return [$user, null];
    }

    /**
     * Must run inside a transaction that locked the staff rows first.
     *
     * @param  array<string, mixed>  $data
     * @return array{jenis_ptk: string, status_kepegawaian_id: int, nip: string|null, nuptk: string|null, gelar_depan: string|null, gelar_belakang: string|null, kode: string|null, sertifikasi: bool, tahun_sertifikasi: int|null}
     *
     * @throws ValidationException
     */
    private function validateStaffFields(array $data, ?Ptk $ignore): array
    {
        $fields = ['jenis_ptk', 'status_kepegawaian_id', 'nip', 'nuptk', 'gelar_depan', 'gelar_belakang', 'kode', 'sertifikasi', 'tahun_sertifikasi'];
        $input = [];

        foreach ($fields as $field) {
            $value = $data[$field] ?? null;

            if (is_string($value)) {
                $value = self::normalizeSpaces($value);
                $value = $value === '' ? null : $value;
            }

            $input[$field] = $value;
        }

        if (is_string($input['kode'])) {
            $input['kode'] = mb_strtoupper($input['kode']);
        }

        $validator = Validator::make($input, [
            'jenis_ptk' => ['required', Rule::enum(JenisPtk::class)],
            'status_kepegawaian_id' => ['bail', 'required', 'integer', $this->selectableEmploymentStatus($ignore)],
            'nip' => ['bail', 'nullable', 'string', 'regex:/^\d{18}\z/', $this->notTakenBy('nip', $ignore)],
            'nuptk' => ['bail', 'nullable', 'string', 'regex:/^\d{16}\z/', $this->notTakenBy('nuptk', $ignore)],
            'gelar_depan' => ['nullable', 'string', 'max:50'],
            'gelar_belakang' => ['nullable', 'string', 'max:100'],
            'kode' => ['bail', 'nullable', 'string', 'max:10', 'regex:/^[A-Z0-9]+\z/', $this->notTakenBy('kode', $ignore)],
            'sertifikasi' => ['required', 'boolean'],
            'tahun_sertifikasi' => ['bail', 'nullable', 'digits:4', 'integer', 'min:1000', 'max:'.now()->year],
        ], [
            'nip.regex' => 'NIP harus terdiri dari tepat 18 digit angka.',
            'nuptk.regex' => 'NUPTK harus terdiri dari tepat 16 digit angka.',
            'kode.regex' => 'Kode hanya boleh berisi huruf dan angka, tanpa spasi.',
            'tahun_sertifikasi.max' => 'Tahun sertifikasi tidak boleh melewati tahun ini.',
        ], [
            'jenis_ptk' => 'jenis',
            'status_kepegawaian_id' => 'status kepegawaian',
            'nip' => 'NIP',
            'nuptk' => 'NUPTK',
            'gelar_depan' => 'gelar depan',
            'gelar_belakang' => 'gelar belakang',
            'kode' => 'kode',
            'sertifikasi' => 'sertifikasi',
            'tahun_sertifikasi' => 'tahun sertifikasi',
        ]);

        $validator->after(function (ValidatorInstance $validator): void {
            $data = $validator->getData();

            if (! filter_var($data['sertifikasi'] ?? false, FILTER_VALIDATE_BOOLEAN) && filled($data['tahun_sertifikasi'] ?? null)) {
                $validator->errors()->add('tahun_sertifikasi', 'Tahun sertifikasi hanya diisi kalau sudah sertifikasi.');
            }
        });

        /** @var array{jenis_ptk: string, status_kepegawaian_id: int|string, nip: string|null, nuptk: string|null, gelar_depan: string|null, gelar_belakang: string|null, kode: string|null, sertifikasi: bool|int|string, tahun_sertifikasi: int|string|null} $validated */
        $validated = $validator->validate();

        return [
            'jenis_ptk' => $validated['jenis_ptk'],
            'status_kepegawaian_id' => (int) $validated['status_kepegawaian_id'],
            'nip' => $validated['nip'],
            'nuptk' => $validated['nuptk'],
            'gelar_depan' => $validated['gelar_depan'],
            'gelar_belakang' => $validated['gelar_belakang'],
            'kode' => $validated['kode'],
            'sertifikasi' => filter_var($validated['sertifikasi'], FILTER_VALIDATE_BOOLEAN),
            'tahun_sertifikasi' => $validated['tahun_sertifikasi'] === null ? null : (int) $validated['tahun_sertifikasi'],
        ];
    }

    /**
     * An existing, active employment status, or the one $ignore already
     * holds. The chosen row is read with a shared lock.
     */
    private function selectableEmploymentStatus(?Ptk $ignore): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
            $status = StatusKepegawaian::query()->sharedLock()->find($value);

            if ($status === null) {
                $fail('Status kepegawaian tidak ditemukan.');

                return;
            }

            if (! $status->status_aktif && $status->getKey() !== $ignore?->status_kepegawaian_id) {
                $fail("Status kepegawaian {$status->nama} sedang tidak aktif dan tidak bisa dipilih.");
            }
        };
    }

    /**
     * Unique rule whose message names the full name of the holder.
     */
    private function notTakenBy(string $kolom, ?Ptk $ignore): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($kolom, $ignore): void {
            $pemilik = Ptk::query()
                ->with('user')
                ->where($kolom, $value)
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore?->getKey()))
                ->first();

            if ($pemilik !== null) {
                $fail(self::LABEL_UNIK[$kolom]." ini sudah dipakai oleh {$pemilik->nama_lengkap}.");
            }
        };
    }

    /**
     * Must run inside a transaction, before the unique checks.
     */
    private function lockStaffRows(): void
    {
        Ptk::query()->lockForUpdate()->get(['id']);
    }

    private function lockStaffRow(Ptk $ptk): Ptk
    {
        return Ptk::query()->with('user')->lockForUpdate()->findOrFail($ptk->getKey());
    }

    private function ensureCanManage(User $pelaku, Ptk $ptk): void
    {
        if (! $this->userService->canManage($pelaku, $ptk->user)) {
            throw PelanggaranAturanPengguna::tidakBolehMengelola();
        }
    }

    /**
     * @param  array{attributes: array<string, mixed>, old: array<string, mixed>}  $changes
     */
    private function log(AksiKepegawaian $aksi, User $pelaku, Ptk $ptk, array $changes): void
    {
        activity(StatusKepegawaianService::LOG_NAME)
            ->causedBy($pelaku)
            ->performedOn($ptk)
            ->event($aksi->value)
            ->withChanges($changes)
            ->log($aksi->label());
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
