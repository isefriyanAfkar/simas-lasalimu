<?php

namespace App\Domains\Academic\Services;

use App\Domains\Academic\Enums\AksiAkademik;
use App\Domains\Academic\Models\MataPelajaran;
use App\Domains\Foundation\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The only place that writes subjects.
 *
 * Rules:
 * - kode is trimmed and uppercased, then 2 to 10 letters and digits, unique
 * - nama is trimmed, at most 100 characters, unique (case-insensitive, as
 *   the column collation is)
 * - urutan is an optional whole number from 0 to 65535; it need not be unique
 * - a new subject is always active; the status changes only through
 *   deactivate() and activate(), never through create() or update()
 * - a subject is never deleted
 */
class MataPelajaranService
{
    /**
     * @param  array{kode: string, nama: string, urutan?: int|string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): MataPelajaran
    {
        $validated = $this->validate($data, null);

        return DB::transaction(fn (): MataPelajaran => MataPelajaran::create([...$validated, 'status_aktif' => true]));
    }

    /**
     * @param  array{kode: string, nama: string, urutan?: int|string|null}  $data
     *
     * @throws ValidationException
     */
    public function update(MataPelajaran $mataPelajaran, array $data): MataPelajaran
    {
        $validated = $this->validate($data, $mataPelajaran);

        return DB::transaction(function () use ($mataPelajaran, $validated): MataPelajaran {
            $mataPelajaran->update($validated);

            return $mataPelajaran;
        });
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
     * @param  array<string, mixed>  $data
     * @return array{kode: string, nama: string, urutan: int|null}
     *
     * @throws ValidationException
     */
    private function validate(array $data, ?MataPelajaran $ignore): array
    {
        if (is_string($data['kode'] ?? null)) {
            $data['kode'] = mb_strtoupper(trim($data['kode']));
        }

        if (is_string($data['nama'] ?? null)) {
            $data['nama'] = trim($data['nama']);
        }

        /** @var array{kode: string, nama: string, urutan?: int|string|null} $validated */
        $validated = Validator::make($data, [
            'kode' => ['required', 'string', 'between:2,10', 'regex:/^[A-Z0-9]+\z/', Rule::unique('mata_pelajaran', 'kode')->ignore($ignore)],
            'nama' => ['required', 'string', 'max:100', Rule::unique('mata_pelajaran', 'nama')->ignore($ignore)],
            'urutan' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ], [
            'kode.regex' => 'Kode hanya boleh berisi huruf dan angka, tanpa spasi.',
            'kode.unique' => 'Kode mata pelajaran ini sudah dipakai.',
            'nama.unique' => 'Nama mata pelajaran ini sudah dipakai.',
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
}
