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
use App\Domains\People\Services\Import\KolomImportSiswa;
use App\Domains\People\Services\Import\PeriksaImportSiswa;
use App\Domains\People\Services\Import\ProsesImportSiswa;
use App\Domains\People\Services\Import\SimpanImportSiswa;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Throwable;

/**
 * The student import from an Excel file, all or nothing. The check and the
 * save run as queued jobs (PeriksaImportSiswa, SimpanImportSiswa), never in
 * a web request.
 *
 * - mulai(): an uploaded file in FOLDER on the private DISK, at most
 *   MAKS_UKURAN_KB, becomes an import for a school year (the active one or a
 *   later one), and its check is queued. One import per school year at a
 *   time: while one is not over (selesai, dibatalkan, gagal), another is
 *   refused.
 * - pratinjau(): the check. The file is read (BacaFileImportSiswa) and every
 *   row applied through the services (ProsesImportSiswa) inside one
 *   transaction that is always rolled back; only the result is stored on
 *   the import (siap, or bergalat with its errors).
 * - antrekanSimpan() then simpan(): only from siap. The rows are applied
 *   again in one transaction, committed only when no row has an error,
 *   together with one log entry for the whole import; the file is deleted
 *   after the commit. An error that appears now (the data changed since the
 *   check) saves nothing and sends the import back to bergalat.
 * - batal(): deletes the file.
 * - tandaiGagal(): a job that stopped; bersihkan(): the scheduled cleanup.
 *
 * Neither run names whoever holds a NIK, whatever the operator may see
 * elsewhere, nor logs a NIK lookup (UserService::tanpaMenyebutPemilikNik).
 *
 * Effects outside the database transaction, checked for the paths the import
 * uses:
 * - the file is only read; it is deleted after a save commits, on batal, on
 *   gagal and by the cleanup;
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

    /**
     * 1000 rows with every column filled take about 160 KB, so 2 MB leaves
     * room for formatting while staying far below the web server limits
     * (upload_max_filesize and post_max_size 256M).
     */
    public const int MAKS_UKURAN_KB = 2048;

    /**
     * Timeout of a check or save job, in seconds. 500 rows took about 20 s
     * on the development laptop; 15 minutes leaves room for 1000 rows on a
     * slower shared server. The queue's retry_after must stay above it
     * (config/queue.php).
     */
    public const int BATAS_WAKTU_JOB = 900;

    /**
     * An import still being checked or saved this long after it started has
     * a job that died without being reported: twice the job timeout.
     */
    public const int BATAS_MACET_DETIK = 2 * self::BATAS_WAKTU_JOB;

    /**
     * A queued import not picked up for this long means the queue does not
     * run; one waiting for the operator this long is cancelled; a file left
     * in FOLDER without an import this long is deleted.
     */
    public const int BATAS_TERLANTAR_JAM = 24;

    public const string PESAN_FILE_TIDAK_ADA = 'File import tidak ditemukan. Unggah ulang file .xlsx.';

    public const string PESAN_PERIKSA_GAGAL = 'Pemeriksaan berhenti sebelum selesai. Tidak ada data yang berubah. Unggah ulang file untuk mencoba lagi.';

    public const string PESAN_SIMPAN_GAGAL = 'Penyimpanan berhenti sebelum selesai. Tidak ada data siswa yang tersimpan. Unggah ulang file untuk mencoba lagi.';

    public const string PESAN_MACET = 'Proses berhenti di tengah jalan (misalnya diputus server). Tidak ada data siswa yang tersimpan. Unggah ulang file untuk mencoba lagi.';

    public const string PESAN_ANTREAN_MATI = 'Antrean tidak berjalan selama 24 jam, jadi file ini tidak pernah diproses. Minta pengelola server memeriksa cron, lalu unggah ulang file.';

    public const string PESAN_TERLANTAR = 'Dibatalkan otomatis: tidak dilanjutkan dalam 24 jam.';

    public function __construct(
        private BacaFileImportSiswa $bacaFile,
        private ProsesImportSiswa $proses,
        private PeriodeAkademik $periodeAkademik,
    ) {}

    /**
     * The school years an import may target: the active one and later ones,
     * id => name, earliest first. Empty without an active school year.
     *
     * @return array<int, string>
     */
    public function opsiTahunAjaran(): array
    {
        $aktif = $this->periodeAkademik->tahunAjaranAktif();

        if ($aktif === null) {
            return [];
        }

        return TahunAjaran::query()
            ->whereDate('tanggal_mulai', '>=', $aktif->tanggal_mulai->toDateString())
            ->orderBy('tanggal_mulai')
            ->pluck('nama', 'id')
            ->all();
    }

    /**
     * Imports that are not over, newest first.
     *
     * @return Collection<int, ImportSiswa>
     */
    public function belumBerakhir(): Collection
    {
        return ImportSiswa::query()
            ->with('tahunAjaran')
            ->whereIn('status', self::statusBelumBerakhir())
            ->latest('id')
            ->get();
    }

    /**
     * The uploaded file is deleted when it is refused.
     *
     * @throws ValidationException when $path is not an uploaded .xlsx in FOLDER, or is too large
     * @throws PelanggaranAturanAkademik when the school year is before the active one
     * @throws PelanggaranAturanKesiswaan when another import of the school year is not over
     */
    public function mulai(User $pelaku, TahunAjaran $tahunAjaran, string $path, string $namaFile): ImportSiswa
    {
        if (! $this->fileAda($path)) {
            throw ValidationException::withMessages(['file' => self::PESAN_FILE_TIDAK_ADA]);
        }

        try {
            if (Storage::disk(self::DISK)->size($path) > self::MAKS_UKURAN_KB * 1024) {
                throw ValidationException::withMessages(['file' => self::pesanTerlaluBesar()]);
            }

            $aktif = $this->periodeAkademik->tahunAjaranAktifAtauGagal();

            if ($tahunAjaran->tanggal_mulai->lt($aktif->tanggal_mulai)) {
                throw PelanggaranAturanAkademik::tahunAjaranSudahLewat($tahunAjaran->nama);
            }

            $namaFile = mb_substr(trim(basename(str_replace('\\', '/', $namaFile))), 0, 255);

            $import = DB::transaction(function () use ($pelaku, $tahunAjaran, $path, $namaFile): ImportSiswa {
                // Serializes two uploads for the same school year.
                TahunAjaran::query()->whereKey($tahunAjaran->getKey())->lockForUpdate()->first(['id']);

                $berjalan = ImportSiswa::query()
                    ->where('tahun_ajaran_id', $tahunAjaran->getKey())
                    ->whereIn('status', self::statusBelumBerakhir())
                    ->latest('id')
                    ->first();

                if ($berjalan !== null) {
                    throw PelanggaranAturanKesiswaan::importLainBerjalan($tahunAjaran->nama, $berjalan->nama_file, $berjalan->status->label());
                }

                return ImportSiswa::query()->create([
                    'user_id' => $pelaku->getKey(),
                    'tahun_ajaran_id' => $tahunAjaran->getKey(),
                    'nama_file' => $namaFile === '' ? 'import.xlsx' : $namaFile,
                    'path' => $path,
                    'status' => StatusImportSiswa::Menunggu,
                ]);
            });
        } catch (Throwable $exception) {
            $this->hapusFile($path);

            throw $exception;
        }

        PeriksaImportSiswa::dispatch((int) $import->getKey());

        return $import;
    }

    /**
     * The check: nothing but the import itself is written.
     *
     * @throws PelanggaranAturanKesiswaan when the import is over or being saved
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
     * Queue the save of a siap import: it becomes menyimpan at once, so a
     * second click is refused.
     *
     * @throws PelanggaranAturanKesiswaan when the import is not siap
     */
    public function antrekanSimpan(ImportSiswa $import): ImportSiswa
    {
        $diubah = ImportSiswa::query()
            ->whereKey($import->getKey())
            ->where('status', StatusImportSiswa::Siap->value)
            ->update(['status' => StatusImportSiswa::Menyimpan->value, 'diproses_sejak' => null]);

        if ($diubah !== 1) {
            throw PelanggaranAturanKesiswaan::importBelumSiap();
        }

        SimpanImportSiswa::dispatch((int) $import->getKey());

        return $import->refresh();
    }

    /**
     * From siap, or from menyimpan as queued by antrekanSimpan().
     *
     * @throws PelanggaranAturanKesiswaan when the import is neither
     */
    public function simpan(ImportSiswa $import): ImportSiswa
    {
        $import->refresh();

        if (! in_array($import->status, [StatusImportSiswa::Siap, StatusImportSiswa::Menyimpan], true)) {
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

        $this->akhiri($import, StatusImportSiswa::Dibatalkan, null);

        return $import;
    }

    /**
     * A job that stopped (PeriksaImportSiswa::failed(), SimpanImportSiswa::
     * failed()): an import still waiting, being checked or being saved
     * becomes gagal with $pesan, and its file is deleted. A save that stopped
     * rolled its transaction back, so nothing was kept.
     */
    public function tandaiGagal(int $importId, string $pesan): void
    {
        $import = ImportSiswa::query()->find($importId);

        if ($import !== null && in_array($import->status, [StatusImportSiswa::Menunggu, StatusImportSiswa::Memeriksa, StatusImportSiswa::Menyimpan], true)) {
            $this->akhiri($import, StatusImportSiswa::Gagal, $pesan);
        }
    }

    /**
     * The scheduled cleanup (simas:bersihkan-import):
     * - checked or saved for longer than BATAS_MACET_DETIK: gagal (a job that
     *   died without failed() being called);
     * - queued for longer than BATAS_TERLANTAR_JAM: gagal (the queue does not
     *   run);
     * - siap or bergalat, untouched for BATAS_TERLANTAR_JAM: dibatalkan;
     * - a file in FOLDER older than BATAS_TERLANTAR_JAM that no import still
     *   uses: deleted.
     *
     * @return array{macet: int, terlantar: int, file: int}
     */
    public function bersihkan(): array
    {
        $hasil = ['macet' => 0, 'terlantar' => 0, 'file' => 0];
        $batasMacet = now()->subSeconds(self::BATAS_MACET_DETIK);
        $batasTerlantar = now()->subHours(self::BATAS_TERLANTAR_JAM);

        $macet = ImportSiswa::query()
            ->whereIn('status', [StatusImportSiswa::Memeriksa->value, StatusImportSiswa::Menyimpan->value])
            ->where(fn ($query) => $query
                ->where('diproses_sejak', '<', $batasMacet)
                ->orWhere(fn ($query) => $query->whereNull('diproses_sejak')->where('updated_at', '<', $batasTerlantar)))
            ->get();

        foreach ($macet as $import) {
            $this->akhiri($import, StatusImportSiswa::Gagal, $import->diproses_sejak === null ? self::PESAN_ANTREAN_MATI : self::PESAN_MACET);
            $hasil['macet']++;
        }

        foreach (ImportSiswa::query()->where('status', StatusImportSiswa::Menunggu->value)->where('updated_at', '<', $batasTerlantar)->get() as $import) {
            $this->akhiri($import, StatusImportSiswa::Gagal, self::PESAN_ANTREAN_MATI);
            $hasil['macet']++;
        }

        foreach (ImportSiswa::query()->whereIn('status', [StatusImportSiswa::Siap->value, StatusImportSiswa::Bergalat->value])->where('updated_at', '<', $batasTerlantar)->get() as $import) {
            $this->akhiri($import, StatusImportSiswa::Dibatalkan, self::PESAN_TERLANTAR);
            $hasil['terlantar']++;
        }

        $dipakai = ImportSiswa::query()->whereNotNull('path')->pluck('path')->all();
        $disk = Storage::disk(self::DISK);

        foreach ($disk->files(self::FOLDER) as $file) {
            if (! in_array($file, $dipakai, true) && $disk->lastModified($file) < $batasTerlantar->getTimestamp() && $this->fileAda($file)) {
                $disk->delete($file);
                $hasil['file']++;
            }
        }

        return $hasil;
    }

    /**
     * The uploaded file with the error of each row in a "Keterangan galat"
     * column (reused when the file already has one) and the cells in error
     * shaded. Written to a temporary file whose path is returned; null when
     * the import has no row error or its file is gone. Only the operator's
     * own cells and the stored messages, which hold no NIK and no name of a
     * NIK holder.
     */
    public function fileGalat(ImportSiswa $import): ?string
    {
        $perBaris = [];

        foreach ($import->galat ?? [] as $galat) {
            if ($galat['baris'] !== null) {
                $perBaris[$galat['baris']][] = $galat;
            }
        }

        if ($perBaris === [] || ! $this->fileAda($import->path)) {
            return null;
        }

        $spreadsheet = IOFactory::load(Storage::disk(self::DISK)->path((string) $import->path));

        try {
            $sheet = $spreadsheet->getSheetByName(KolomImportSiswa::LEMBAR_DATA) ?? $spreadsheet->getSheet(0);
            $kolomKe = [];
            $terakhir = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

            for ($indeks = 1; $indeks <= $terakhir; $indeks++) {
                $kolomKe[mb_strtolower(trim((string) $sheet->getCell([$indeks, 1])->getValue()))] ??= $indeks;
            }

            $kolomGalat = $kolomKe[mb_strtolower(KolomImportSiswa::KOLOM_GALAT)] ?? $terakhir + 1;
            $sheet->setCellValue([$kolomGalat, 1], KolomImportSiswa::KOLOM_GALAT);
            $sheet->getStyle([$kolomGalat, 1])->getFont()->setBold(true);
            $sheet->getColumnDimensionByColumn($kolomGalat)->setWidth(60);

            for ($baris = 2; $baris <= $sheet->getHighestDataRow(); $baris++) {
                $sheet->setCellValue([$kolomGalat, $baris], null);
            }

            foreach ($perBaris as $baris => $daftar) {
                $teks = [];

                foreach ($daftar as $galat) {
                    $judul = $galat['kolom'] === null ? null : KolomImportSiswa::judul($galat['kolom']);
                    $teks[] = $judul === null ? $galat['pesan'] : "{$judul}: {$galat['pesan']}";

                    $indeks = $judul === null ? null : ($kolomKe[mb_strtolower($judul)] ?? null);

                    if ($indeks !== null) {
                        $sheet->getStyle([$indeks, $baris])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8D7DA');
                    }
                }

                $sheet->setCellValue([$kolomGalat, $baris], implode("\n", $teks));
                $sheet->getStyle([$kolomGalat, $baris])->getAlignment()->setWrapText(true);
            }

            $path = tempnam(sys_get_temp_dir(), 'galat-import').'.xlsx';
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

            return $path;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public static function pesanTerlaluBesar(): string
    {
        return sprintf('File terlalu besar: paling besar %d MB. Bagi menjadi beberapa file, paling banyak %d baris per file.', self::MAKS_UKURAN_KB / 1024, KolomImportSiswa::MAKS_BARIS);
    }

    /**
     * @return list<string>
     */
    private static function statusBelumBerakhir(): array
    {
        return array_values(array_map(
            fn (StatusImportSiswa $status): string => $status->value,
            array_filter(StatusImportSiswa::cases(), fn (StatusImportSiswa $status): bool => ! $status->selesai()),
        ));
    }

    private function akhiri(ImportSiswa $import, StatusImportSiswa $status, ?string $pesan): void
    {
        $this->hapusFile($import->path);
        $import->update(['status' => $status, 'pesan' => $pesan, 'path' => null, 'diproses_sejak' => null, 'selesai_pada' => now()]);
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
            $this->akhiri($import, StatusImportSiswa::Gagal, self::PESAN_FILE_TIDAK_ADA);

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
            } catch (Throwable $exception) {
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
