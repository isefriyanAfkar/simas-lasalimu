<?php

namespace App\Domains\Academic\Services;

use App\Domains\Academic\Enums\AksiAkademik;
use App\Domains\Academic\Models\MataPelajaran;
use App\Domains\Academic\Models\Semester;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Foundation\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Explicit activity log entries for the academic services, so
 * TahunAjaranService and SemesterService share one format without
 * depending on each other.
 */
final class AktivitasAkademik
{
    public const string LOG_NAME = 'akademik';

    /**
     * Log an activation; the LogsActivity trait on the models leaves
     * status_aktif out on purpose. Call it inside the writing transaction.
     *
     * The period it replaces, if any, is stored as properties.menggantikan
     * = {id, label}; the label is kept as text so the entry stays readable
     * after that period is deleted.
     */
    public static function logActivation(AksiAkademik $aksi, User $pelaku, TahunAjaran|Semester $subject, TahunAjaran|Semester|null $digantikan = null): void
    {
        $logger = activity(self::LOG_NAME)
            ->causedBy($pelaku)
            ->performedOn($subject)
            ->event($aksi->value)
            ->withChanges(['attributes' => ['status_aktif' => true], 'old' => ['status_aktif' => false]]);

        if ($digantikan !== null) {
            $logger->withProperty('menggantikan', ['id' => $digantikan->getKey(), 'label' => self::label($digantikan)]);
        }

        $logger->log($aksi->label());
    }

    /**
     * Log a status change in either direction (status_aktif old → new) for
     * subjects that are simply switched on and off. Call it inside the
     * writing transaction.
     */
    public static function logStatusChange(AksiAkademik $aksi, User $pelaku, Model $subject, bool $aktif): void
    {
        activity(self::LOG_NAME)
            ->causedBy($pelaku)
            ->performedOn($subject)
            ->event($aksi->value)
            ->withChanges(['attributes' => ['status_aktif' => $aktif], 'old' => ['status_aktif' => ! $aktif]])
            ->log($aksi->label());
    }

    /**
     * Short name as shown in the activity log: "TA 2026/2027",
     * "Ganjil 2026/2027" or "MTK Matematika".
     */
    public static function label(TahunAjaran|Semester|MataPelajaran $subject): string
    {
        return match (true) {
            $subject instanceof TahunAjaran => "TA {$subject->nama}",
            $subject instanceof Semester => "{$subject->jenis->label()} {$subject->tahunAjaran->nama}",
            default => "{$subject->kode} {$subject->nama}",
        };
    }
}
