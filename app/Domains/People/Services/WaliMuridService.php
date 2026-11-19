<?php

namespace App\Domains\People\Services;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\KunciNikTidakSiap;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Enums\AksiKesiswaan;
use App\Domains\People\Enums\HubunganWali;
use App\Domains\People\Models\SiswaWali;
use App\Domains\People\Models\WaliMurid;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Parent and guardian records on their own. Adding a parent to a student,
 * and every relation rule, live in SiswaWaliService.
 *
 * - The account and biodata follow UserService (validasiAkunWali,
 *   simpanAkunWali): only the name is required, NIK protection included.
 *   The account stays inactive and its username (wali00123) never changes.
 * - The phone number is not unique (father and mother often share one); it
 *   is stored normalized (62...), so cari() and calonGanda() find it typed
 *   as 08..., +62... or 62..., also in part.
 * - pekerjaan is optional, at most 100 characters.
 * - The gender may not contradict a relation to any student: an ayah is
 *   male, an ibu female. An empty gender is filled in from such a relation.
 * - A parent who is the main contact of a student cannot be marked as died
 *   until another main contact is chosen.
 * - A parent linked to no student may be deleted, together with the
 *   account; a linked one cannot.
 * - The died flag and deleting are logged explicitly (log "kesiswaan").
 *
 * Every write locks the parent row first, then reads its relations with a
 * lock (the order SiswaWaliService uses after locking the student).
 */
class WaliMuridService
{
    public const string LOG_NAME = 'kesiswaan';

    /**
     * The fields of the parent form that belong to the account.
     *
     * @var list<string>
     */
    public const array FIELD_AKUN = ['name', 'email', 'nik', 'hapus_nik', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir', 'agama', 'alamat', 'no_hp', 'foto'];

    private const int ATTEMPTS = 3;

    public function __construct(private UserService $userService) {}

    /**
     * Parents whose name or phone number matches, by name, with their
     * children loaded. A phone number may be typed as 08..., +62... or
     * 62..., also in part; % and _ are matched literally.
     *
     * @return Collection<int, WaliMurid>
     */
    public function cari(string $kataKunci, int $batas = 50): Collection
    {
        $kataKunci = trim($kataKunci);

        if ($kataKunci === '') {
            return new Collection;
        }

        return $this->saringPencarian(WaliMurid::query(), $kataKunci)
            ->with(['user', 'siswaWali.siswa.user'])
            ->orderBy(User::query()->select('name')->whereColumn('users.id', 'wali_murid.user_id'))
            ->limit($batas)
            ->get();
    }

    /**
     * The search of cari() as a query filter, for the Wali Murid list.
     *
     * @param  Builder<WaliMurid>  $query
     * @return Builder<WaliMurid>
     */
    public function saringPencarian(Builder $query, string $kataKunci): Builder
    {
        $pola = '%'.self::escapeLike(trim($kataKunci)).'%';
        $nomor = self::potonganNomor(trim($kataKunci));

        return $query->whereHas('user', fn ($user) => $user
            ->where('name', 'like', $pola)
            ->when($nomor !== null, fn ($user) => $user->orWhere('no_hp', 'like', '%'.self::escapeLike((string) $nomor).'%')));
    }

    /**
     * Parents that may be the same person as a new one: the same phone
     * number (in any format) or the same NIK. Used to warn before saving; it
     * never blocks.
     *
     * @return Collection<int, WaliMurid>
     */
    public function calonGanda(?string $noHp, ?string $nik): Collection
    {
        $noHp = filled($noHp) ? UserService::normalisasiNoHp((string) $noHp) : null;
        $userIds = [];

        if (is_string($nik) && preg_match('/^\d{16}$/', $nik) === 1) {
            try {
                $userIds = User::query()->denganNik($nik)->pluck('id')->all();
            } catch (KunciNikTidakSiap) {
                $userIds = [];
            }
        }

        if ($noHp === null && $userIds === []) {
            return new Collection;
        }

        return WaliMurid::query()
            ->with(['user', 'siswaWali.siswa.user'])
            ->where(fn ($query) => $query
                ->when($noHp !== null, fn ($query) => $query->orWhereHas('user', fn ($user) => $user->where('no_hp', $noHp)))
                ->when($userIds !== [], fn ($query) => $query->orWhereIn('user_id', $userIds)))
            ->get();
    }

    /**
     * Create a parent and the account. For SiswaWaliService, inside its
     * transaction, after it validated $data (validasi()).
     *
     * @param  array<string, mixed>  $data
     */
    public function buat(User $pelaku, array $data): WaliMurid
    {
        $pekerjaan = $this->validasiPekerjaan($data);
        $user = $this->userService->simpanAkunWali($pelaku, null, Arr::only($data, self::FIELD_AKUN));

        return app(CauserResolver::class)->withCauser($pelaku, fn (): WaliMurid => WaliMurid::create([
            'user_id' => $user->getKey(),
            'pekerjaan' => $pekerjaan,
            'meninggal' => false,
        ]));
    }

    /**
     * The field errors of a new parent ($wali null) or of a change, without
     * writing: the account fields and pekerjaan. Must run inside a
     * transaction.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>>
     */
    public function galatData(array $data, ?WaliMurid $wali): array
    {
        return [
            ...self::errorsOf(fn () => $this->userService->validasiAkunWali(Arr::only($data, self::FIELD_AKUN), $wali?->user)),
            ...self::errorsOf(fn () => $this->validasiPekerjaan($data)),
        ];
    }

    /**
     * Change the biodata and the job. The username and the account status
     * never change here.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function ubah(User $pelaku, WaliMurid $wali, array $data): WaliMurid
    {
        $this->ensureCanManage($pelaku, $wali);

        return DB::transaction(function () use ($pelaku, $wali, $data): WaliMurid {
            $wali = $this->lockParent($wali);
            [$data, $galat] = $this->jenisKelaminSesuaiHubungan($wali, $data);
            $galat = [...$this->galatData($data, $wali), ...$galat];

            if ($galat !== []) {
                throw ValidationException::withMessages($galat);
            }

            $pekerjaan = $this->validasiPekerjaan($data);
            $this->userService->simpanAkunWali($pelaku, $wali->user, Arr::only($data, self::FIELD_AKUN));
            app(CauserResolver::class)->withCauser($pelaku, fn (): bool => $wali->update(['pekerjaan' => $pekerjaan]));

            return $wali;
        }, self::ATTEMPTS);
    }

    /**
     * Mark a parent as died, or take the mark back. A parent who is still the
     * main contact of a student cannot be marked.
     *
     * @throws PelanggaranAturanKesiswaan
     */
    public function ubahStatusMeninggal(User $pelaku, WaliMurid $wali, bool $meninggal): WaliMurid
    {
        $this->ensureCanManage($pelaku, $wali);

        return DB::transaction(function () use ($pelaku, $wali, $meninggal): WaliMurid {
            $wali = $this->lockParent($wali);

            if ($wali->meninggal === $meninggal) {
                return $wali;
            }

            if ($meninggal) {
                $namaSiswa = $wali->siswaWali()
                    ->with('siswa.user')
                    ->where('utama', true)
                    ->lockForUpdate()
                    ->get()
                    ->map(fn (SiswaWali $siswaWali): string => $siswaWali->siswa->user->name)
                    ->sort()
                    ->values()
                    ->all();

                if ($namaSiswa !== []) {
                    throw PelanggaranAturanKesiswaan::waliUtamaTidakBolehMeninggal($wali->user->name, $namaSiswa);
                }
            }

            $wali->update(['meninggal' => $meninggal]);

            $this->log(
                $meninggal ? AksiKesiswaan::WaliDitandaiMeninggal : AksiKesiswaan::TandaMeninggalWaliDibatalkan,
                $pelaku,
                $wali,
                ['attributes' => ['meninggal' => $meninggal], 'old' => ['meninggal' => ! $meninggal]],
            );

            return $wali;
        }, self::ATTEMPTS);
    }

    /**
     * Delete a parent linked to no student, together with the account.
     *
     * @throws PelanggaranAturanKesiswaan
     */
    public function hapus(User $pelaku, WaliMurid $wali): void
    {
        $this->ensureCanManage($pelaku, $wali);

        DB::transaction(function () use ($pelaku, $wali): void {
            $wali = $this->lockParent($wali);

            // Locking read: sees a relation committed by a save that held
            // this parent's row until now, and blocks new ones until commit.
            $jumlahSiswa = $wali->siswaWali()->sharedLock()->count();

            if ($jumlahSiswa > 0) {
                throw PelanggaranAturanKesiswaan::waliMasihTerhubung($wali->user->name, $jumlahSiswa);
            }

            $this->log(AksiKesiswaan::WaliDihapus, $pelaku, $wali, ['attributes' => [], 'old' => ['nama' => $wali->user->name]]);

            $user = $wali->user;
            $wali->delete();
            $this->userService->hapusAkunWali($pelaku, $user);
        }, self::ATTEMPTS);
    }

    /**
     * pekerjaan: optional, at most 100 characters, inner whitespace
     * collapsed.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function validasiPekerjaan(array $data): ?string
    {
        $pekerjaan = $data['pekerjaan'] ?? null;

        if (is_string($pekerjaan)) {
            $pekerjaan = self::normalizeSpaces($pekerjaan);
            $pekerjaan = $pekerjaan === '' ? null : $pekerjaan;
        }

        /** @var array{pekerjaan: string|null} $validated */
        $validated = Validator::make(['pekerjaan' => $pekerjaan], [
            'pekerjaan' => ['nullable', 'string', 'max:100'],
        ], [], ['pekerjaan' => 'pekerjaan'])->validate();

        return $validated['pekerjaan'];
    }

    /**
     * The gender checked against every ayah or ibu relation of the parent:
     * empty is filled in from it, a contradiction is a field error naming the
     * student. Must run with the parent row locked.
     *
     * @param  array<string, mixed>  $data
     * @return array{array<string, mixed>, array<string, list<string>>}
     */
    private function jenisKelaminSesuaiHubungan(WaliMurid $wali, array $data): array
    {
        $relasi = $wali->siswaWali()
            ->with('siswa.user')
            ->whereIn('hubungan', [HubunganWali::Ayah->value, HubunganWali::Ibu->value])
            ->lockForUpdate()
            ->get();

        foreach ($relasi as $siswaWali) {
            $wajib = $siswaWali->hubungan->jenisKelamin();

            if ($wajib === null) {
                continue;
            }

            if (blank($data['jenis_kelamin'] ?? null)) {
                $data['jenis_kelamin'] = $wajib->value;

                continue;
            }

            if ($data['jenis_kelamin'] !== $wajib->value) {
                return [$data, ['jenis_kelamin' => [sprintf(
                    '%s adalah %s %s, jadi harus %s.',
                    $wali->user->name,
                    mb_strtolower($siswaWali->hubungan->label()),
                    $siswaWali->siswa->user->name,
                    mb_strtolower($wajib->label()),
                )]]];
            }
        }

        return [$data, []];
    }

    private function lockParent(WaliMurid $wali): WaliMurid
    {
        return WaliMurid::query()->with('user')->lockForUpdate()->findOrFail($wali->getKey());
    }

    private function ensureCanManage(User $pelaku, WaliMurid $wali): void
    {
        if (! $this->userService->canManage($pelaku, $wali->user)) {
            throw PelanggaranAturanPengguna::tidakBolehMengelola();
        }
    }

    /**
     * @param  array{attributes: array<string, mixed>, old: array<string, mixed>}  $changes
     */
    private function log(AksiKesiswaan $aksi, User $pelaku, WaliMurid $wali, array $changes): void
    {
        activity(self::LOG_NAME)
            ->causedBy($pelaku)
            ->performedOn($wali)
            ->event($aksi->value)
            ->withChanges($changes)
            ->log($aksi->label());
    }

    /**
     * The digits to look for in phone numbers when $kataKunci looks like (a
     * part of) a phone number, normalized like stored numbers; otherwise
     * null.
     */
    private static function potonganNomor(string $kataKunci): ?string
    {
        if (preg_match('/^[\d\s\-+().]+$/', $kataKunci) !== 1 || preg_match_all('/\d/', $kataKunci) < 3) {
            return null;
        }

        return (string) preg_replace('/\D/', '', UserService::normalisasiNoHp($kataKunci));
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
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

    private static function normalizeSpaces(string $value): string
    {
        return trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $value));
    }
}
