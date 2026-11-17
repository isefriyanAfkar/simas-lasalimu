<?php

namespace App\Domains\Academic\Models;

use App\Domains\Academic\Services\AktivitasAkademik;
use Illuminate\Database\Eloquent\Model;

/**
 * For data that can be deleted: every activity log entry about it (from the
 * LogsActivity trait or an explicit activity() call) stores its readable name
 * in properties.label, so the Activity Log can still name it after the row is
 * gone.
 *
 * spatie/laravel-activitylog calls beforeActivityLogged() on the subject just
 * before saving each entry.
 *
 * @phpstan-require-extends Model
 */
trait LabelDiLogAktivitas
{
    public function beforeActivityLogged(Model $activity, string $eventName): void
    {
        $activity->setAttribute('properties', collect($activity->getAttribute('properties'))->put('label', AktivitasAkademik::label($this)));
    }
}
