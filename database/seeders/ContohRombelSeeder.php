<?php

namespace Database\Seeders;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\PeriodeAkademik;
use App\Domains\Academic\Services\RombelService;
use App\Domains\Academic\Services\RombelSiswa;
use App\Domains\Foundation\Settings\PengaturanSekolah;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Development rombel for trying the student import (simas:contoh-import).
 * Never in production, never called from DatabaseSeeder; run by hand:
 *
 *     php artisan db:seed --class=ContohRombelSeeder
 *
 * The school year is named in the environment variable VARIABEL (for
 * example SIMAS_CONTOH_TAHUN_AJARAN=2027/2028), or is the active one.
 *
 * Adds rombel of KAPASITAS seats through RombelService, going round the
 * grades of the school level in Pengaturan Sekolah in turn (X-1, XI-1,
 * XII-1, X-2, ...) and skipping names that already exist, until the school
 * year has KURSI_KOSONG free seats: capacity minus active students, a rombel
 * without capacity counting as none. A second run therefore creates nothing.
 */
class ContohRombelSeeder extends Seeder
{
    public const string VARIABEL = 'SIMAS_CONTOH_TAHUN_AJARAN';

    private const int KURSI_KOSONG = 540;

    private const int KAPASITAS = 36;

    private const int NOMOR_TERBESAR = 99;

    public function run(RombelService $rombelService, RombelSiswa $rombelSiswa, PeriodeAkademik $periode, PengaturanSekolah $pengaturan): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Seeder contoh rombel tidak boleh dijalankan di production.');
        }

        if ($pengaturan->jenjang === null) {
            throw new RuntimeException(RombelService::PESAN_JENJANG_KOSONG);
        }

        $tahunAjaran = $this->tahunAjaran($periode);
        $kurang = self::KURSI_KOSONG;
        $nomorTerpakai = [];

        foreach (Rombel::query()->where('tahun_ajaran_id', $tahunAjaran->getKey())->get() as $rombel) {
            $nomorTerpakai[$rombel->tingkat->value][$rombel->nomor] = true;

            if ($rombel->kapasitas !== null) {
                $kurang -= max(0, $rombel->kapasitas - $rombelSiswa->jumlahSiswaAktif($rombel));
            }
        }

        $dibuat = [];

        while ($kurang > 0) {
            $adaYangDibuat = false;

            foreach (Tingkat::untukJenjang($pengaturan->jenjang) as $tingkat) {
                $nomor = 1;

                while (isset($nomorTerpakai[$tingkat->value][$nomor])) {
                    $nomor++;
                }

                if ($kurang <= 0 || $nomor > self::NOMOR_TERBESAR) {
                    continue;
                }

                $rombel = $rombelService->create([
                    'tahun_ajaran_id' => (int) $tahunAjaran->getKey(),
                    'tingkat' => $tingkat->value,
                    'nomor' => $nomor,
                    'kapasitas' => self::KAPASITAS,
                ]);
                $nomorTerpakai[$tingkat->value][$nomor] = true;
                $kurang -= self::KAPASITAS;
                $dibuat[] = $rombel->nama;
                $adaYangDibuat = true;
            }

            if (! $adaYangDibuat) {
                throw new RuntimeException("Nomor rombel di tahun ajaran {$tahunAjaran->nama} sudah habis.");
            }
        }

        $this->command?->info($dibuat === []
            ? "Kursi kosong di tahun ajaran {$tahunAjaran->nama} sudah cukup; tidak ada rombel baru."
            : sprintf('Rombel dibuat di tahun ajaran %s: %s.', $tahunAjaran->nama, implode(', ', $dibuat)));
    }

    private function tahunAjaran(PeriodeAkademik $periode): TahunAjaran
    {
        $nama = getenv(self::VARIABEL);

        if (! is_string($nama) || $nama === '') {
            return $periode->tahunAjaranAktifAtauGagal();
        }

        return TahunAjaran::query()->where('nama', $nama)->first()
            ?? throw new RuntimeException("Tahun ajaran {$nama} tidak ditemukan.");
    }
}
