<?php

namespace App\Domains\People\Services;

use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\PelanggaranAturanAkademik;
use App\Domains\Academic\Services\PeriodeAkademik;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Enums\AksiKesiswaan;
use App\Domains\People\Enums\StatusImportSiswa;
use App\Domains\People\Models\ImportSiswa;
use App\Domains\People\Services\Import\BacaFileImportSiswa;
use App\Domains\People\Services\Import\HasilImportSiswa;
use App\Domains\People\Services\Import\ProsesImportSiswa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The student import from an Excel file, all or nothing.
 *
 * - mulai(): an uploaded file in FOLDER on the private DISK becomes an
 *   import for a school year (the active one or a later one).
 * - pratinjau(): the check. The file is read (BacaFileImportSiswa) and every
 *   row applied through the services (ProsesImportSiswa) inside one
 *   transaction that is always rolled back; only the result is stored on
 *   the import (siap, or bergalat with its errors).
 * - simpan(): only from siap. The rows are applied again in one transaction,
 *   committed only when no row has an error, together with one log entry
 *   for the whole import; the file is deleted after the commit. An error
 *   that appears now (the data changed since the check) saves nothing and
 *   sends the import back to bergalat.
 * - batal(): deletes the file.
 *
 * Neither run names whoever holds a NIK, whatever the operator may see
 * elsewhere, nor logs a NIK lookup (UserService::tanpaMenyebutPemilikNik).
 *
 * Effects outside the database transaction, checked for the paths the import
 * uses:
 * - the file is only read; it is deleted after a save commits or on batal;
 * - UserService registers a Cari NIK log entry after a rollback or commit
 *   only when it names a NIK holder, which it never does here; it deletes a
 *   replaced photo after a commit, and the import never sends a photo;
 * - the lock marks (KunciSekaliPerTransaksi) are memory and follow the
 *   rollback;
 * - a rolled-back check still uses up AUTO_INCREMENT values (gaps in ids),
 *   and may warm the permission cache with what the database already holds.
 * The services fire no other events, jobs or file writes.
 *
 * Every change is made in the name of the operator who uploaded the file.
 */
class ImportSiswaService
{
    public const string DISK = 'local';

    public const string FOLDER = 'imports';

    public const string PESAN_FILE_TIDAK_ADA = 'File import tidak ditemukan. Unggah ulang file .xlsx.';

    public function __construct(
        private BacaFileImportSiswa $bacaFile,
        private ProsesImportSiswa $proses,
        private PeriodeAkademik $periodeAkademik,
    ) {}

    /**
     * @throws ValidationException when $path is not an uploaded .xlsx in FOLDER
     * @throws PelanggaranAturanAkademik when the school year is before the active one
     */
    public function mulai(User $pelaku, TahunAjaran $tahunAjaran, string $path, string $namaFile): ImportSiswa
    {
        if (! $this->fileAda($path)) {
            throw ValidationException::withMessages(['file' => self::PESAN_FILE_TIDAK_ADA]);
        }

        $aktif = $this->periodeAkademik->tahunAjaranAktifAtauGagal();

        if ($tahunAjaran->tanggal_mulai->lt($aktif->tanggal_mulai)) {
            throw PelanggaranAturanAkademik::tahunAjaranSudahLewat($tahunAjaran->nama);
        }

        $namaFile = mb_substr(trim(basename(str_replace('\\', '/', $namaFile))), 0, 255);

        return ImportSiswa::query()->create([
            'user_id' => $pelaku->getKey(),
            'tahun_ajaran_id' => $tahunAjaran->getKey(),
            'nama_file' => $namaFile === '' ? 'import.xlsx' : $namaFile,
            'path' => $path,
            'status' => StatusImportSiswa::Menunggu,
        ]);
    }

    /**
     * The check: nothing but the import itself is written.
     *
     * @throws PelanggaranAturanKesiswaan when the import is over
     */
    public function pratinjau(ImportSiswa $import): ImportSiswa
    {
        $import->refresh();

        if ($import->status->selesai() || $import->status === StatusImportSiswa::Menyimpan) {
            throw PelanggaranAturanKesiswaan::importSudahBerakhir();
        }

        $import->update(['status' => StatusImportSiswa::Memeriksa, 'diproses_sejak' => now()]);

        $hasil = $this->jalankan($import, function (): void {
            // A check never keeps anything.
        });

        if ($hasil === null) {
            return $import;
        }

        $import->update([
            'status' => $hasil->bersih() ? StatusImportSiswa::Siap : StatusImportSiswa::Bergalat,
            ...$this->hasil($hasil),
        ]);

        return $import;
    }

    /**
     * @throws PelanggaranAturanKesiswaan when the import is not siap
     */
    public function simpan(ImportSiswa $import): ImportSiswa
    {
        $import->refresh();

        if ($import->status !== StatusImportSiswa::Siap) {
            throw PelanggaranAturanKesiswaan::importBelumSiap();
        }

        $import->update(['status' => StatusImportSiswa::Menyimpan, 'diproses_sejak' => now()]);

        $hasil = $this->jalankan($import, function (HasilImportSiswa $hasil) use ($import): bool {
            if (! $hasil->bersih()) {
                return false;
            }

            $path = (string) $import->path;

            activity(SiswaService::LOG_NAME)
                ->causedBy($import->user)
                ->performedOn($import)
                ->event(AksiKesiswaan::SiswaDiimpor->value)
                ->withProperties([
                    'nama_file' => $import->nama_file,
                    'tahun_ajaran' => $import->tahunAjaran->nama,
                    'ringkasan' => $hasil->ringkasan,
                ])
                ->log(AksiKesiswaan::SiswaDiimpor->label());

            $import->update([
                'status' => StatusImportSiswa::Selesai,
                'path' => null,
                'selesai_pada' => now(),
                ...$this->hasil($hasil),
            ]);

            DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($path));

            return true;
        });

        if ($hasil !== null && ! $hasil->bersih()) {
            $import->update(['status' => StatusImportSiswa::Bergalat, ...$this->hasil($hasil)]);
        }

        return $import;
    }

    /**
     * @throws PelanggaranAturanKesiswaan when the import is over or being saved
     */
    public function batal(ImportSiswa $import): ImportSiswa
    {
        $import->refresh();

        if ($import->status->selesai() || $import->status === StatusImportSiswa::Menyimpan) {
            throw PelanggaranAturanKesiswaan::importSudahBerakhir();
        }

        $this->hapusFile($import->path);
        $import->update(['status' => StatusImportSiswa::Dibatalkan, 'path' => null, 'diproses_sejak' => null, 'selesai_pada' => now()]);

        return $import;
    }

    /**
     * Read the file and apply its rows in one transaction. $simpan decides
     * with the result: true commits, anything else rolls back. Returns null
     * when the file is gone (the import is then gagal).
     *
     * @param  callable(HasilImportSiswa): (bool|void)  $simpan
     */
    private function jalankan(ImportSiswa $import, callable $simpan): ?HasilImportSiswa
    {
        $path = (string) $import->path;

        if (! $this->fileAda($path)) {
            $import->update(['status' => StatusImportSiswa::Gagal, 'pesan' => self::PESAN_FILE_TIDAK_ADA, 'path' => null, 'diproses_sejak' => null]);

            return null;
        }

        $baca = $this->bacaFile->baca(Storage::disk(self::DISK)->path($path), $import->tahunAjaran);

        if ($baca->galatFile !== []) {
            return HasilImportSiswa::galatFile($baca->galatFile);
        }

        return UserService::tanpaMenyebutPemilikNik(function () use ($import, $baca, $simpan): HasilImportSiswa {
            DB::beginTransaction();

            try {
                $hasil = $this->proses->jalankan($import->user, $import->tahunAjaran, $baca->baris);
                $tetap = $simpan($hasil) === true;
            } catch (\Throwable $exception) {
                DB::rollBack();

                throw $exception;
            }

            $tetap ? DB::commit() : DB::rollBack();

            return $hasil;
        });
    }

    /**
     * @return array{ringkasan: array<string, int>, galat: list<array{baris: int|null, kolom: string|null, pesan: string}>, pindah: list<array{baris: int, nis: string, nama: string, dari: string, ke: string}>, diproses_sejak: null}
     */
    private function hasil(HasilImportSiswa $hasil): array
    {
        return ['ringkasan' => $hasil->ringkasan, 'galat' => $hasil->galat, 'pindah' => $hasil->pindah, 'diproses_sejak' => null];
    }

    /**
     * An uploaded file directly in FOLDER (no other folder, no "..") with
     * the .xlsx extension, that exists on DISK.
     */
    private function fileAda(?string $path): bool
    {
        return is_string($path)
            && preg_match('#^'.self::FOLDER.'/[A-Za-z0-9_-]+\.xlsx$#', $path) === 1
            && Storage::disk(self::DISK)->exists($path);
    }

    /**
     * Deletes only a file directly in FOLDER.
     */
    private function hapusFile(?string $path): void
    {
        if ($this->fileAda($path)) {
            Storage::disk(self::DISK)->delete((string) $path);
        }
    }
}
