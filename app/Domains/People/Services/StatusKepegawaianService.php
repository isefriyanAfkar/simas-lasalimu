<?php

namespace App\Domains\People\Services;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\KeunikanDatabase;
use App\Domains\People\Enums\AksiKepegawaian;
use App\Domains\People\Models\StatusKepegawaian;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The only place that writes employment statuses.
 *
 * Rules:
 * - nama is trimmed and its inner whitespace collapsed to single spaces
 *   (Unicode-aware, so a pasted non-breaking space counts as a space), at
 *   most 50 characters, unique (case-insensitive, as the column collation is)
 * - urutan is an optional whole number from 0 to 65535; it need not be unique
 * - a new status is always active; the status changes only through
 *   deactivate() and activate(), never through create() or update()
 * - a status is never deleted
 *
 * Uniqueness is checked inside a transaction that first locks the status
 * rows, so two saves are serialized; KeunikanDatabase turns any unique index
 * violation that still happens into a field error.
 */
class StatusKepegawaianService
{
    public const string LOG_NAME = 'kepegawaian';

    /**
     * Two first saves can deadlock on the gap lock; the retry then sees the
     * other row and fails validation.
     */
    private const int ATTEMPTS = 3;

    /**
     * @var array<string, array{string, string}>
     */
    private const array INDEKS_UNIK = [
        'status_kepegawaian_nama_unique' => ['nama', 'Nama ini baru saja dipakai status kepegawaian lain. Periksa daftar, lalu simpan lagi.'],
    ];

    /**
     * @param  array{nama: string|null, urutan?: int|string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): StatusKepegawaian
    {
        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): StatusKepegawaian => DB::transaction(
            fn (): StatusKepegawaian => StatusKepegawaian::create([...$this->validate($data, null), 'status_aktif' => true]),
            self::ATTEMPTS,
        ));
    }

    /**
     * @param  array{nama: string|null, urutan?: int|string|null}  $data
     *
     * @throws ValidationException
     */
    public function update(StatusKepegawaian $statusKepegawaian, array $data): StatusKepegawaian
    {
        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): StatusKepegawaian => DB::transaction(function () use ($statusKepegawaian, $data): StatusKepegawaian {
            $statusKepegawaian->update($this->validate($data, $statusKepegawaian));

            return $statusKepegawaian;
        }, self::ATTEMPTS));
    }

    /**
     * The statuses a staff form may offer, id => nama in display order: the
     * active ones, plus $saatIni (the status the staff member already holds)
     * even when it has been deactivated since.
     *
     * @return array<int, string>
     */
    public function opsiUntukDipilih(?int $saatIni): array
    {
        return StatusKepegawaian::query()
            ->where(fn ($query) => $query
                ->where('status_aktif', true)
                ->when($saatIni !== null, fn ($query) => $query->orWhereKey($saatIni)))
            ->urutanTampilan()
            ->pluck('nama', 'id')
            ->all();
    }

    public function deactivate(User $pelaku, StatusKepegawaian $statusKepegawaian): StatusKepegawaian
    {
        return $this->changeStatus($pelaku, $statusKepegawaian, false);
    }

    public function activate(User $pelaku, StatusKepegawaian $statusKepegawaian): StatusKepegawaian
    {
        return $this->changeStatus($pelaku, $statusKepegawaian, true);
    }

    /**
     * Does nothing, and logs nothing, when the status is already $aktif.
     */
    private function changeStatus(User $pelaku, StatusKepegawaian $statusKepegawaian, bool $aktif): StatusKepegawaian
    {
        return DB::transaction(function () use ($pelaku, $statusKepegawaian, $aktif): StatusKepegawaian {
            $terkunci = StatusKepegawaian::query()->lockForUpdate()->findOrFail($statusKepegawaian->getKey());

            if ($terkunci->status_aktif === $aktif) {
                return $statusKepegawaian->refresh();
            }

            $terkunci->update(['status_aktif' => $aktif]);

            $aksi = $aktif ? AksiKepegawaian::StatusKepegawaianDiaktifkan : AksiKepegawaian::StatusKepegawaianDinonaktifkan;

            activity(self::LOG_NAME)
                ->causedBy($pelaku)
                ->performedOn($terkunci)
                ->event($aksi->value)
                ->withChanges(['attributes' => ['status_aktif' => $aktif], 'old' => ['status_aktif' => ! $aktif]])
                ->log($aksi->label());

            return $statusKepegawaian->refresh();
        });
    }

    /**
     * Must run inside a transaction: the status rows are locked first so two
     * concurrent saves cannot both pass the unique check.
     *
     * @param  array<string, mixed>  $data
     * @return array{nama: string, urutan: int|null}
     *
     * @throws ValidationException
     */
    private function validate(array $data, ?StatusKepegawaian $ignore): array
    {
        StatusKepegawaian::query()->lockForUpdate()->get(['id']);

        if (is_string($data['nama'] ?? null)) {
            $data['nama'] = self::normalizeSpaces($data['nama']);
        }

        /** @var array{nama: string, urutan?: int|string|null} $validated */
        $validated = Validator::make($data, [
            'nama' => ['bail', 'required', 'string', 'max:50', $this->notTaken($ignore)],
            'urutan' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ], [], [
            'nama' => 'nama',
            'urutan' => 'urutan',
        ])->validate();

        $urutan = $validated['urutan'] ?? null;

        return [
            'nama' => $validated['nama'],
            'urutan' => $urutan === null || $urutan === '' ? null : (int) $urutan,
        ];
    }

    /**
     * Unique rule whose message names the status that already exists, and
     * says so when it is inactive (it may be hidden by the status filter, and
     * can be activated again instead).
     */
    private function notTaken(?StatusKepegawaian $ignore): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
            $pemilik = StatusKepegawaian::query()
                ->where('nama', $value)
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore?->getKey()))
                ->first();

            if ($pemilik === null) {
                return;
            }

            $fail($pemilik->status_aktif
                ? "Status kepegawaian {$pemilik->nama} sudah ada."
                : "Status kepegawaian {$pemilik->nama} sudah ada, tetapi sedang tidak aktif. Aktifkan lagi status itu bila ingin memakainya.");
        };
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
