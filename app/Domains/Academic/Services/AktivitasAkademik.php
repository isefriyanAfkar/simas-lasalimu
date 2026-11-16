<?php

namespace App\Domains\Academic\Services;

use App\Domains\Academic\Enums\AksiAkademik;
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
     */
    public static function logActivation(AksiAkademik $aksi, User $pelaku, Model $subject): void
    {
        activity(self::LOG_NAME)
            ->causedBy($pelaku)
            ->performedOn($subject)
            ->event($aksi->value)
            ->withChanges(['attributes' => ['status_aktif' => true], 'old' => ['status_aktif' => false]])
            ->log($aksi->label());
    }
}
