<?php

namespace App\Domains\People\Services\Import;

use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\RombelSiswa;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Services\ImportSiswaService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * A sample import file for trying the import (simas:contoh-import): the
 * import template of a school year filled with fake students that pass the
 * check. Never in production. Reads the database, never writes to it; the
 * file goes to FOLDER on the private local disk.
 *
 * Every row:
 * - a NIS of the form <year the school year starts><5 digits> that no
 *   student has and no account uses as username;
 * - a made-up name, gender, a birth date 15 to 17 years before the school
 *   year, and a rombel of the school year: rows go round the rombel in
 *   turn, each only while it has a free seat (capacity minus its active
 *   students; no capacity is never full);
 * - ayah and ibu with a made-up name, job and a fake phone number (0899...),
 *   never a NIK. One row in ten (the 10th, 20th, ...) is a younger sibling
 *   of the row before it and repeats both parents exactly, so the import
 *   links them to the same parents.
 */
final class ContohImportSiswa
{
    public const string FOLDER = 'contoh-import';

    /**
     * @var list<string>
     */
    private const array NAMA_LAKI = ['Ahmad', 'Budi', 'Dedi', 'Eko', 'Fajar', 'Gilang', 'Hendra', 'Irfan', 'Joko', 'Kurniawan', 'La Ode', 'Muhammad', 'Rahmat', 'Rizky', 'Sandi', 'Taufik', 'Wahyu', 'Yusuf'];

    /**
     * @var list<string>
     */
    private const array NAMA_PEREMPUAN = ['Ani', 'Citra', 'Dewi', 'Eka', 'Fitri', 'Hasnah', 'Indah', 'Lestari', 'Maya', 'Nur', 'Putri', 'Rina', 'Sari', 'Siti', 'Wa Ode', 'Wulan', 'Yanti', 'Zahra'];

    /**
     * @var list<string>
     */
    private const array NAMA_BELAKANG = ['Saputra', 'Hidayat', 'Pratama', 'Ramadhan', 'Lestari', 'Wijaya', 'Kusuma', 'Halim', 'Rahman', 'Syahputra', 'Nugroho', 'Santoso', 'Hamid', 'Basri', 'Amir'];

    /**
     * @var list<string>
     */
    private const array PEKERJAAN = ['Petani', 'Nelayan', 'Wiraswasta', 'Guru', 'PNS', 'Pedagang', 'Buruh', 'Sopir', 'Karyawan swasta', 'Ibu rumah tangga'];

    public function __construct(
        private TemplateImportSiswa $template,
        private RombelSiswa $rombelSiswa,
        private ImportSiswaService $importSiswa,
    ) {}

    /**
     * The file's path on the private local disk.
     *
     * @throws GalatContohImport
     */
    public function buat(TahunAjaran $tahunAjaran, int $jumlahBaris): string
    {
        if (app()->isProduction()) {
            throw GalatContohImport::production();
        }

        if ($jumlahBaris < 1 || $jumlahBaris > KolomImportSiswa::MAKS_BARIS) {
            throw GalatContohImport::jumlahBaris();
        }

        if (! array_key_exists((int) $tahunAjaran->getKey(), $this->importSiswa->opsiTahunAjaran())) {
            throw GalatContohImport::tahunAjaranLewat($tahunAjaran->nama);
        }

        $rombel = $this->rombelPerBaris($tahunAjaran, $jumlahBaris);
        $nis = $this->nisBaru((int) $tahunAjaran->tanggal_mulai->format('Y'), $jumlahBaris);
        $spreadsheet = $this->template->buat($tahunAjaran);
        $sheet = $spreadsheet->getSheetByName(KolomImportSiswa::LEMBAR_DATA) ?? $spreadsheet->getSheet(0);
        $nomorHp = random_int(10_000_000, 49_999_999);
        $sebelumnya = null;

        foreach ($nis as $indeks => $satuNis) {
            $laki = random_int(0, 1) === 1;
            $belakang = self::pilih(self::NAMA_BELAKANG);
            $baris = [
                'nis' => $satuNis,
                'nama' => self::pilih($laki ? self::NAMA_LAKI : self::NAMA_PEREMPUAN).' '.$belakang,
                'jenis_kelamin' => $laki ? 'L' : 'P',
                'tanggal_lahir' => sprintf('%02d-%02d-%d', random_int(1, 28), random_int(1, 12), (int) $tahunAjaran->tanggal_mulai->format('Y') - random_int(15, 17)),
                'rombel' => $rombel[$indeks],
            ];

            if ($sebelumnya !== null && $indeks % 10 === 9) {
                $orangTua = $sebelumnya;
            } else {
                $orangTua = [
                    'ayah_nama' => self::pilih(self::NAMA_LAKI).' '.$belakang,
                    'ayah_no_hp' => '0899'.($nomorHp++),
                    'ayah_pekerjaan' => self::pilih(array_slice(self::PEKERJAAN, 0, -1)),
                    'ibu_nama' => self::pilih(self::NAMA_PEREMPUAN).' '.self::pilih(self::NAMA_BELAKANG),
                    'ibu_no_hp' => '0899'.($nomorHp++),
                    'ibu_pekerjaan' => self::pilih(self::PEKERJAAN),
                ];
            }

            $sebelumnya = $orangTua;

            foreach ([...$baris, ...$orangTua] as $kolom => $isi) {
                $sheet->setCellValueExplicit(KolomImportSiswa::huruf($kolom).($indeks + 2), $isi, DataType::TYPE_STRING);
            }
        }

        $sementara = ImportSiswaService::fileSementaraBaru('contoh-import');

        try {
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save($sementara);
            $path = sprintf('%s/contoh-import-%s-%d-baris-%s-%s.xlsx', self::FOLDER, Str::slug($tahunAjaran->nama), $jumlahBaris, now()->format('Ymd-His'), Str::lower(Str::random(4)));
            Storage::disk(ImportSiswaService::DISK)->put($path, (string) file_get_contents($sementara));
        } finally {
            $spreadsheet->disconnectWorksheets();

            if (is_file($sementara)) {
                unlink($sementara);
            }
        }

        return $path;
    }

    /**
     * The rombel name of each row, round the rombel of the school year in
     * turn, each only while it has a free seat.
     *
     * @return list<string>
     *
     * @throws GalatContohImport
     */
    private function rombelPerBaris(TahunAjaran $tahunAjaran, int $jumlahBaris): array
    {
        $kosong = [];

        foreach (Rombel::query()->with('tahunAjaran')->where('tahun_ajaran_id', $tahunAjaran->getKey())->orderBy('tingkat')->orderBy('nomor')->get() as $rombel) {
            $kosong[$rombel->nama] = $rombel->kapasitas === null
                ? $jumlahBaris
                : max(0, $rombel->kapasitas - $this->rombelSiswa->jumlahSiswaAktif($rombel));
        }

        $jumlahKursi = array_sum($kosong);

        if ($jumlahKursi < $jumlahBaris) {
            throw GalatContohImport::kursiKurang($tahunAjaran->nama, $jumlahKursi, $jumlahBaris);
        }

        $hasil = [];

        do {
            $ditempatkan = false;

            foreach ($kosong as $nama => $sisa) {
                if ($sisa > 0 && count($hasil) < $jumlahBaris) {
                    $hasil[] = (string) $nama;
                    $kosong[$nama]--;
                    $ditempatkan = true;
                }
            }
        } while ($ditempatkan && count($hasil) < $jumlahBaris);

        return $hasil;
    }

    /**
     * $jumlah NIS from <year>00001 up, skipping those a student has or an
     * account uses as username.
     *
     * @return list<string>
     */
    private function nisBaru(int $tahun, int $jumlah): array
    {
        $terpakai = array_flip([
            ...Siswa::query()->where('nis', 'like', "{$tahun}%")->pluck('nis')->all(),
            ...User::query()->where('username', 'like', "{$tahun}%")->pluck('username')->all(),
        ]);
        $hasil = [];

        for ($nomor = 1; count($hasil) < $jumlah; $nomor++) {
            $nis = sprintf('%d%05d', $tahun, $nomor);

            if (! isset($terpakai[$nis])) {
                $hasil[] = $nis;
            }
        }

        return $hasil;
    }

    /**
     * @param  list<string>  $pilihan
     */
    private static function pilih(array $pilihan): string
    {
        return $pilihan[array_rand($pilihan)];
    }
}
