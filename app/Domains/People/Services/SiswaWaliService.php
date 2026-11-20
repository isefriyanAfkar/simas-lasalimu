<?php

namespace App\Domains\People\Services;

use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Enums\JenisKunciIdentitas;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\KeunikanDatabase;
use App\Domains\Foundation\Services\KunciIdentitas;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Enums\AksiKesiswaan;
use App\Domains\People\Enums\HubunganWali;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Models\SiswaWali;
use App\Domains\People\Models\WaliMurid;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place that links students and their parents or guardians
 * (siswa_wali).
 *
 * Rules, per student:
 * - a parent is linked at most once
 * - at most one ayah and one ibu; any number of wali
 * - at most one utama (main contact). The first parent added becomes utama,
 *   unless marked as died; then the next living one does
 * - the utama cannot be let go before another utama is chosen, so the only
 *   parent cannot be let go either
 * - a parent marked as died cannot become utama
 * - an ayah is male and an ibu female: an empty gender is filled in from
 *   the relation, a contradiction is a field error
 * The database keeps the first three as well (unique indexes, see the
 * migration); KeunikanDatabase turns a violation that slips past the checks
 * into a field error.
 *
 * tambahkan() links an existing parent (wali "ada", wali_murid_id; one
 * parent may have several children) or creates a new one with an inactive
 * account (wali "baru", see WaliMuridService). Every change is logged
 * explicitly (log "kesiswaan", subject the student), without the NIK.
 *
 * Lock order, the same in every path that touches siswa_wali (see
 * KunciIdentitas): the identity locks siswa → wali_murid (→ akun when the
 * path can write an account, as tambahkan() always can), taken first, then S (student row) → W (parent row)
 * → users (the parent's account) → R (siswa_wali rows).
 * WaliMuridService and Kelola Pengguna start further along the same order
 * (wali_murid → W → users → R, or akun → users → R) and never go back to an
 * earlier step, so no two paths can wait on each other in a cycle. Locking
 * a row again that the transaction already holds takes no new lock. A
 * deadlock that still happens (for example on index gaps while two
 * students get their first parent) is retried (ATTEMPTS); one that remains
 * after the retries becomes a "coba lagi" notification (GalatKonkurensi).
 */
class SiswaWaliService
{
    public const string PESAN_UTAMA_BERUBAH = 'Wali utama siswa ini baru saja diubah pengguna lain. Muat ulang, lalu coba lagi.';

    private const int ATTEMPTS = 3;

    /**
     * @var array<string, array{string, string}>
     */
    private const array INDEKS_UNIK = [
        'siswa_wali_siswa_id_wali_murid_id_unique' => ['wali_murid_id', 'Wali ini baru saja ditambahkan ke siswa ini oleh pengguna lain. Muat ulang, lalu coba lagi.'],
        'siswa_wali_siswa_id_penanda_ayah_ibu_unique' => ['hubungan', 'Ayah atau ibu siswa ini baru saja ditambahkan pengguna lain. Muat ulang, lalu coba lagi.'],
        'siswa_wali_siswa_id_penanda_utama_unique' => ['wali_murid_id', self::PESAN_UTAMA_BERUBAH],
    ];

    public function __construct(
        private UserService $userService,
        private WaliMuridService $waliMuridService,
    ) {}

    /**
     * Lock order: the identity locks siswa → wali_murid → akun, then S → W
     * and the parent's account (existing parent) → R.
     *
     * @param  array<string, mixed>  $data  wali ("baru" or "ada"), wali_murid_id, hubungan, and for a new parent the account fields and pekerjaan
     *
     * @throws ValidationException
     */
    public function tambahkan(User $pelaku, Siswa $siswa, array $data): SiswaWali
    {
        $this->ensureCanManage($pelaku, $siswa->user);

        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): SiswaWali => DB::transaction(function () use ($pelaku, $siswa, $data): SiswaWali {
            KunciIdentitas::kunci(JenisKunciIdentitas::Siswa, JenisKunciIdentitas::WaliMurid, JenisKunciIdentitas::Akun);
            $siswa = $this->lockStudent($siswa);
            $galat = [];
            $mode = $data['wali'] ?? null;
            $hubungan = HubunganWali::tryFrom(is_string($data['hubungan'] ?? null) ? $data['hubungan'] : '');
            $wali = null;

            if (! in_array($mode, ['baru', 'ada'], true)) {
                $galat['wali'] = ['Pilih wali yang sudah ada atau buat wali baru.'];
            }

            if ($hubungan === null) {
                $galat['hubungan'] = ['Pilih hubungan: ayah, ibu, atau wali.'];
            }

            if ($mode === 'ada') {
                [$wali, $pesan] = $this->parentToLink($pelaku, $data['wali_murid_id'] ?? null);

                if ($pesan !== null) {
                    $galat['wali_murid_id'] = [$pesan];
                }

                if ($wali !== null && $hubungan !== null && ($pesan = self::bertentangan($wali, $hubungan)) !== null) {
                    $galat['hubungan'] = [$pesan];
                }
            }

            if ($mode === 'baru') {
                $wajib = $hubungan?->jenisKelamin();

                if ($wajib !== null && blank($data['jenis_kelamin'] ?? null)) {
                    $data['jenis_kelamin'] = $wajib->value;
                }

                $galat = [...$galat, ...$this->waliMuridService->galatData($pelaku, $data, null)];

                if ($wajib !== null && ($data['jenis_kelamin'] ?? null) !== $wajib->value && ! isset($galat['jenis_kelamin'])) {
                    $galat['jenis_kelamin'] = [$hubungan === HubunganWali::Ayah ? 'Ayah harus laki-laki.' : 'Ibu harus perempuan.'];
                }
            }

            // R: only after S, W and users are held.
            if ($wali !== null && ! isset($galat['wali_murid_id']) && $this->sudahTerhubung($siswa, $wali)) {
                $galat['wali_murid_id'] = ["{$wali->user->name} sudah menjadi wali murid {$siswa->user->name}."];
            }

            if ($hubungan !== null && ! isset($galat['hubungan']) && ($pesan = $this->sudahAdaAyahAtauIbu($siswa, $hubungan, null)) !== null) {
                $galat['hubungan'] = [$pesan];
            }

            if ($galat !== [] || $hubungan === null) {
                throw ValidationException::withMessages($galat);
            }

            if ($wali === null) {
                $wali = $this->waliMuridService->buat($pelaku, $data);
                $wali->load('user');
            } else {
                $this->isiJenisKelamin($pelaku, $wali, $hubungan);
            }

            $adaUtama = SiswaWali::query()->where('siswa_id', $siswa->getKey())->where('utama', true)->lockForUpdate()->exists();

            $siswaWali = SiswaWali::create([
                'siswa_id' => $siswa->getKey(),
                'wali_murid_id' => $wali->getKey(),
                'hubungan' => $hubungan,
                'utama' => ! $adaUtama && ! $wali->meninggal,
            ]);

            $this->log(AksiKesiswaan::WaliDitambahkan, $pelaku, $siswa, [
                'attributes' => ['wali' => $wali->user->name, 'hubungan' => $hubungan->value, 'utama' => $siswaWali->utama],
            ]);

            return $siswaWali;
        }, self::ATTEMPTS));
    }

    /**
     * Lock order: siswa → wali_murid (identity locks) → S → W and the
     * parent's account → R.
     *
     * @param  array{hubungan?: mixed}  $data
     *
     * @throws ValidationException
     * @throws PelanggaranAturanKesiswaan
     */
    public function ubahHubungan(User $pelaku, SiswaWali $siswaWali, array $data): SiswaWali
    {
        $this->ensureCanManage($pelaku, $siswaWali->siswa->user);

        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): SiswaWali => DB::transaction(function () use ($pelaku, $siswaWali, $data): SiswaWali {
            KunciIdentitas::kunci(JenisKunciIdentitas::Siswa, JenisKunciIdentitas::WaliMurid);
            $siswa = $this->lockStudent($siswaWali->siswa);
            $wali = $this->lockParent($siswaWali->wali_murid_id);
            $siswaWali = $this->lockRelation($siswaWali);
            $hubungan = HubunganWali::tryFrom(is_string($data['hubungan'] ?? null) ? $data['hubungan'] : '');

            if ($hubungan === null) {
                throw ValidationException::withMessages(['hubungan' => 'Pilih hubungan: ayah, ibu, atau wali.']);
            }

            if ($hubungan === $siswaWali->hubungan) {
                return $siswaWali;
            }

            $pesan = self::bertentangan($wali, $hubungan) ?? $this->sudahAdaAyahAtauIbu($siswa, $hubungan, $siswaWali);

            if ($pesan !== null) {
                throw ValidationException::withMessages(['hubungan' => $pesan]);
            }

            $lama = $siswaWali->hubungan;
            $siswaWali->update(['hubungan' => $hubungan]);
            $this->isiJenisKelamin($pelaku, $wali, $hubungan);

            $this->log(AksiKesiswaan::HubunganWaliDiubah, $pelaku, $siswa, [
                'attributes' => ['wali' => $wali->user->name, 'hubungan' => $hubungan->value],
                'old' => ['wali' => $wali->user->name, 'hubungan' => $lama->value],
            ]);

            return $siswaWali;
        }, self::ATTEMPTS));
    }

    /**
     * Make $siswaWali the student's only main contact. Lock order: siswa →
     * wali_murid (identity locks) → S → W and the parent's account → R.
     *
     * @throws PelanggaranAturanKesiswaan
     * @throws ValidationException when a concurrent change made another one utama
     */
    public function jadikanUtama(User $pelaku, SiswaWali $siswaWali): SiswaWali
    {
        $this->ensureCanManage($pelaku, $siswaWali->siswa->user);

        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): SiswaWali => DB::transaction(function () use ($pelaku, $siswaWali): SiswaWali {
            KunciIdentitas::kunci(JenisKunciIdentitas::Siswa, JenisKunciIdentitas::WaliMurid);
            $siswa = $this->lockStudent($siswaWali->siswa);
            $wali = $this->lockParent($siswaWali->wali_murid_id);
            $siswaWali = $this->lockRelation($siswaWali);

            if ($siswaWali->utama) {
                throw PelanggaranAturanKesiswaan::sudahWaliUtama($wali->user->name);
            }

            if ($wali->meninggal) {
                throw PelanggaranAturanKesiswaan::waliMeninggalTidakBolehUtama($wali->user->name);
            }

            $lama = SiswaWali::query()
                ->with('waliMurid.user')
                ->where('siswa_id', $siswa->getKey())
                ->where('utama', true)
                ->lockForUpdate()
                ->first();

            $lama?->update(['utama' => false]);
            $siswaWali->update(['utama' => true]);

            $this->log(AksiKesiswaan::WaliUtamaDiganti, $pelaku, $siswa, [
                'attributes' => ['wali_utama' => $wali->user->name],
                'old' => ['wali_utama' => $lama?->waliMurid->user->name],
            ]);

            return $siswaWali;
        }, self::ATTEMPTS));
    }

    /**
     * Let a parent go from a student. The parent record and account stay
     * (WaliMuridService::hapus() deletes a parent without children). Lock
     * order: siswa → wali_murid (identity locks) → S → W and the parent's
     * account → R.
     *
     * @throws PelanggaranAturanKesiswaan
     */
    public function lepas(User $pelaku, SiswaWali $siswaWali): void
    {
        $this->ensureCanManage($pelaku, $siswaWali->siswa->user);

        DB::transaction(function () use ($pelaku, $siswaWali): void {
            KunciIdentitas::kunci(JenisKunciIdentitas::Siswa, JenisKunciIdentitas::WaliMurid);
            $siswa = $this->lockStudent($siswaWali->siswa);
            $wali = $this->lockParent($siswaWali->wali_murid_id);
            $siswaWali = $this->lockRelation($siswaWali);

            if ($siswaWali->utama) {
                throw PelanggaranAturanKesiswaan::waliUtamaTidakBolehDilepas($wali->user->name, $siswa->user->name);
            }

            $siswaWali->delete();

            $this->log(AksiKesiswaan::WaliDilepas, $pelaku, $siswa, [
                'attributes' => [],
                'old' => ['wali' => $wali->user->name, 'hubungan' => $siswaWali->hubungan->value],
            ]);
        }, self::ATTEMPTS);
    }

    /**
     * The existing parent to link, locked with the account (W → users), or
     * the reason it cannot be linked. The relation rows are checked later.
     *
     * @return array{WaliMurid|null, string|null}
     */
    private function parentToLink(User $pelaku, mixed $waliId): array
    {
        if (! is_int($waliId) && ! (is_string($waliId) && ctype_digit($waliId))) {
            return [null, 'Pilih wali murid yang sudah ada.'];
        }

        $wali = $this->lockParentOrNull((int) $waliId);

        if ($wali === null) {
            return [null, 'Wali murid tidak ditemukan.'];
        }

        if (! $this->userService->canManage($pelaku, $wali->user)) {
            return [null, PelanggaranAturanPengguna::tidakBolehMengelola()->getMessage()];
        }

        return [$wali, null];
    }

    /**
     * Whether the student already has this parent. A locking read (R).
     */
    private function sudahTerhubung(Siswa $siswa, WaliMurid $wali): bool
    {
        return SiswaWali::query()
            ->where('siswa_id', $siswa->getKey())
            ->where('wali_murid_id', $wali->getKey())
            ->lockForUpdate()
            ->exists();
    }

    /**
     * "Budi sudah punya ayah: Ahmad." when the student already has another
     * parent with this single relation. A locking read (R).
     */
    private function sudahAdaAyahAtauIbu(Siswa $siswa, HubunganWali $hubungan, ?SiswaWali $kecuali): ?string
    {
        if ($hubungan === HubunganWali::Wali) {
            return null;
        }

        $ada = SiswaWali::query()
            ->with('waliMurid.user')
            ->where('siswa_id', $siswa->getKey())
            ->where('hubungan', $hubungan->value)
            ->when($kecuali, fn ($query) => $query->whereKeyNot($kecuali?->getKey()))
            ->lockForUpdate()
            ->first();

        return $ada === null ? null : "{$siswa->user->name} sudah punya ".mb_strtolower($hubungan->label()).": {$ada->waliMurid->user->name}.";
    }

    /**
     * "Siti tercatat perempuan, tidak bisa menjadi ayah." when the parent's
     * stored gender contradicts the relation.
     */
    private static function bertentangan(WaliMurid $wali, HubunganWali $hubungan): ?string
    {
        $wajib = $hubungan->jenisKelamin();
        $tercatat = $wali->user->jenis_kelamin;

        if ($wajib === null || ! $tercatat instanceof JenisKelamin || $tercatat === $wajib) {
            return null;
        }

        return sprintf('%s tercatat %s, tidak bisa menjadi %s.', $wali->user->name, mb_strtolower($tercatat->label()), mb_strtolower($hubungan->label()));
    }

    /**
     * The parent's account row is already locked (lockParent), so this takes
     * no new lock.
     */
    private function isiJenisKelamin(User $pelaku, WaliMurid $wali, HubunganWali $hubungan): void
    {
        $wajib = $hubungan->jenisKelamin();

        if ($wajib !== null && $wali->user->jenis_kelamin === null) {
            $this->userService->isiJenisKelaminWali($pelaku, $wali->user, $wajib);
        }
    }

    /**
     * Step S of the lock order.
     */
    private function lockStudent(Siswa $siswa): Siswa
    {
        return Siswa::query()->with('user')->lockForUpdate()->findOrFail($siswa->getKey());
    }

    /**
     * Steps W and users of the lock order: the parent row, then the parent's
     * account row.
     */
    private function lockParent(int $waliId): WaliMurid
    {
        return $this->lockParentOrNull($waliId) ?? throw PelanggaranAturanKesiswaan::hubunganSudahTidakAda();
    }

    private function lockParentOrNull(int $waliId): ?WaliMurid
    {
        $wali = WaliMurid::query()->with('user')->lockForUpdate()->find($waliId);

        if ($wali !== null) {
            User::query()->whereKey($wali->user_id)->lockForUpdate()->first(['id']);
        }

        return $wali;
    }

    /**
     * Step R of the lock order. The relation may have been let go by someone
     * else meanwhile.
     */
    private function lockRelation(SiswaWali $siswaWali): SiswaWali
    {
        return SiswaWali::query()->with('waliMurid.user')->lockForUpdate()->find($siswaWali->getKey())
            ?? throw PelanggaranAturanKesiswaan::hubunganSudahTidakAda();
    }

    private function ensureCanManage(User $pelaku, User $user): void
    {
        if (! $this->userService->canManage($pelaku, $user)) {
            throw PelanggaranAturanPengguna::tidakBolehMengelola();
        }
    }

    /**
     * @param  array{attributes: array<string, mixed>, old?: array<string, mixed>}  $changes
     */
    private function log(AksiKesiswaan $aksi, User $pelaku, Siswa $siswa, array $changes): void
    {
        activity(WaliMuridService::LOG_NAME)
            ->causedBy($pelaku)
            ->performedOn($siswa)
            ->event($aksi->value)
            ->withChanges($changes)
            ->log($aksi->label());
    }
}
