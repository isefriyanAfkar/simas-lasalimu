<?php

namespace App\Domains\Academic\Services;

use App\Domains\Academic\Enums\AksiAkademik;
use App\Domains\Academic\Models\MataPelajaran;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\KeunikanDatabase;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The only place that writes subjects.
 *
 * Rules:
 * - kode is trimmed and uppercased, then 2 to 10 letters and digits, unique
 * - nama is trimmed and its inner whitespace collapsed to single spaces
 *   (Unicode-aware, so a pasted non-breaking space counts as a space), at
 *   most 100 characters, unique (case-insensitive, as the column collation is)
 * - urutan is an optional whole number from 0 to 65535; it need not be unique
 * - a new subject is always active; the status changes only through
 *   deactivate() and activate(), never through create() or update()
 * - a subject is never deleted
 *
 * Uniqueness is checked inside a transaction that first locks the subject
 * rows, so two saves are serialized; KeunikanDatabase turns any unique index
 * violation that still happens into a field error.
 */
class MataPelajaranService
{
    /**
     * Two first saves into an empty table can deadlock on the gap lock; the
     * retry then sees the other row and fails validation.
     */
    private const int ATTEMPTS = 3;

    /**
     * @var array<string, array{string, string}>
     */
    private const array INDEKS_UNIK = [
        'mata_pelajaran_kode_unique' => ['kode', 'Kode ini baru saja dipakai mata pelajaran lain. Periksa daftar, lalu simpan lagi.'],
        'mata_pelajaran_nama_unique' => ['nama', 'Nama ini baru saja dipakai mata pelajaran lain. Periksa daftar, lalu simpan lagi.'],
    ];

    /**
     * @param  array{kode: string, nama: string, urutan?: int|string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): MataPelajaran
    {
        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): MataPelajaran => DB::transaction(
            fn (): MataPelajaran => MataPelajaran::create([...$this->validate($data, null), 'status_aktif' => true]),
            self::ATTEMPTS,
        ));
    }

    /**
     * @param  array{kode: string, nama: string, urutan?: int|string|null}  $data
     *
     * @throws ValidationException
     */
    public function update(MataPelajaran $mataPelajaran, array $data): MataPelajaran
    {
        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): MataPelajaran => DB::transaction(function () use ($mataPelajaran, $data): MataPelajaran {
            $mataPelajaran->update($this->validate($data, $mataPelajaran));

            return $mataPelajaran;
        }, self::ATTEMPTS));
    }

    public function deactivate(User $pelaku, MataPelajaran $mataPelajaran): MataPelajaran
    {
        return $this->changeStatus($pelaku, $mataPelajaran, false);
    }

    public function activate(User $pelaku, MataPelajaran $mataPelajaran): MataPelajaran
    {
        return $this->changeStatus($pelaku, $mataPelajaran, true);
    }

    /**
     * Does nothing, and logs nothing, when the status is already $aktif.
     */
    private function changeStatus(User $pelaku, MataPelajaran $mataPelajaran, bool $aktif): MataPelajaran
    {
        return DB::transaction(function () use ($pelaku, $mataPelajaran, $aktif): MataPelajaran {
            $terkunci = MataPelajaran::query()->lockForUpdate()->findOrFail($mataPelajaran->getKey());

            if ($terkunci->status_aktif === $aktif) {
                return $mataPelajaran->refresh();
            }

            $terkunci->update(['status_aktif' => $aktif]);
            AktivitasAkademik::logStatusChange(
                $aktif ? AksiAkademik::MataPelajaranDiaktifkan : AksiAkademik::MataPelajaranDinonaktifkan,
                $pelaku,
                $terkunci,
                $aktif,
            );

            return $mataPelajaran->refresh();
        });
    }

    /**
     * Must run inside a transaction: the subject rows are locked first so two
     * concurrent saves cannot both pass the unique checks.
     *
     * @param  array<string, mixed>  $data
     * @return array{kode: string, nama: string, urutan: int|null}
     *
     * @throws ValidationException
     */
    private function validate(array $data, ?MataPelajaran $ignore): array
    {
        MataPelajaran::query()->lockForUpdate()->get(['id']);

        if (is_string($data['kode'] ?? null)) {
            $data['kode'] = mb_strtoupper(self::normalizeSpaces($data['kode']));
        }

        if (is_string($data['nama'] ?? null)) {
            $data['nama'] = self::normalizeSpaces($data['nama']);
        }

        /** @var array{kode: string, nama: string, urutan?: int|string|null} $validated */
        $validated = Validator::make($data, [
            'kode' => ['bail', 'required', 'string', 'between:2,10', 'regex:/^[A-Z0-9]+\z/', $this->notTakenBy('kode', $ignore)],
            'nama' => ['bail', 'required', 'string', 'max:100', $this->notTakenBy('nama', $ignore)],
            'urutan' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ], [
            'kode.regex' => 'Kode hanya boleh berisi huruf dan angka, tanpa spasi.',
        ], [
            'kode' => 'kode',
            'nama' => 'nama',
            'urutan' => 'urutan',
        ])->validate();

        $urutan = $validated['urutan'] ?? null;

        return [
            'kode' => $validated['kode'],
            'nama' => $validated['nama'],
            'urutan' => $urutan === null || $urutan === '' ? null : (int) $urutan,
        ];
    }

    /**
     * Unique rule whose message names the subject that already holds the
     * value, and says so when that subject is inactive (it may be hidden by
     * the status filter, and can be activated again instead).
     */
    private function notTakenBy(string $kolom, ?MataPelajaran $ignore): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($kolom, $ignore): void {
            $pemilik = MataPelajaran::query()
                ->where($kolom, $value)
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore?->getKey()))
                ->first();

            if ($pemilik === null) {
                return;
            }

            $pesan = $kolom === 'kode'
                ? "Kode {$pemilik->kode} sudah dipakai oleh mata pelajaran {$pemilik->nama}"
                : "Nama ini sudah dipakai oleh mata pelajaran {$pemilik->kode}";

            $fail($pemilik->status_aktif
                ? "{$pesan}."
                : "{$pesan} yang sedang tidak aktif. Aktifkan lagi mata pelajaran itu bila ingin memakainya.");
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
