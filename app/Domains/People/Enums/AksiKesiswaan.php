<?php

namespace App\Domains\People\Enums;

/**
 * Student (kesiswaan) actions recorded explicitly in the activity log
 * (`event` column, log "kesiswaan"). Plain create/update is recorded by the
 * LogsActivity trait.
 */
enum AksiKesiswaan: string
{
    case SiswaStatusDiubah = 'siswa_status_diubah';
    case SiswaDiaktifkanKembali = 'siswa_diaktifkan_kembali';
    case WaliDitambahkan = 'wali_ditambahkan';
    case HubunganWaliDiubah = 'hubungan_wali_diubah';
    case WaliUtamaDiganti = 'wali_utama_diganti';
    case WaliDilepas = 'wali_dilepas';
    case WaliDitandaiMeninggal = 'wali_ditandai_meninggal';
    case TandaMeninggalWaliDibatalkan = 'tanda_meninggal_wali_dibatalkan';
    case WaliDihapus = 'wali_dihapus';
    case SiswaDiimpor = 'import_siswa';

    public function label(): string
    {
        return match ($this) {
            self::SiswaStatusDiubah => 'Status siswa diubah',
            self::SiswaDiaktifkanKembali => 'Siswa diaktifkan kembali',
            self::WaliDitambahkan => 'Wali murid ditambahkan',
            self::HubunganWaliDiubah => 'Hubungan wali murid diubah',
            self::WaliUtamaDiganti => 'Wali utama diganti',
            self::WaliDilepas => 'Wali murid dilepas',
            self::WaliDitandaiMeninggal => 'Wali murid ditandai meninggal',
            self::TandaMeninggalWaliDibatalkan => 'Tanda meninggal wali murid dibatalkan',
            self::WaliDihapus => 'Wali murid dihapus',
            self::SiswaDiimpor => 'Siswa diimpor dari Excel',
        };
    }
}
