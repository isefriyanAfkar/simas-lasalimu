<?php

namespace App\Filament\Support;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Livewire\Component;
use PDOException;
use Throwable;

use function Livewire\on;

/**
 * The one place that turns a deadlock (MariaDB error 1213) or a lock wait
 * timeout (1205) that is still there after the service's transaction retries
 * (attempts) into the notification "Sedang ada perubahan lain pada data ini.
 * Coba lagi." instead of a server error. It applies to every Livewire
 * component, so to every Filament page, form, table and action; the database
 * transaction has already been rolled back.
 *
 * Bulk placement (PenempatanRombelService::tempatkanBanyak) keeps its own
 * handling, because it reports such a failure per student and goes on.
 * Every other error is left alone.
 */
final class GalatKonkurensi
{
    public const string PESAN = 'Sedang ada perubahan lain pada data ini. Coba lagi.';

    /**
     * MariaDB/MySQL error codes: 1213 deadlock, 1205 lock wait timeout.
     *
     * @var list<int>
     */
    private const array KODE = [1213, 1205];

    /**
     * Register the listener; called once from AppServiceProvider::boot().
     */
    public static function daftarkan(): void
    {
        on('exception', function (mixed $target, Throwable $exception, Closure $stopPropagation): void {
            if (! $target instanceof Component || ! self::cocok($exception)) {
                return;
            }

            Notification::make()->danger()->title(self::PESAN)->send();
            $stopPropagation();
        });
    }

    /**
     * Whether $exception, or one it wraps (Laravel rethrows a deadlock inside
     * a nested transaction as a DeadlockException), carries error 1213 or
     * 1205.
     */
    public static function cocok(Throwable $exception): bool
    {
        for ($galat = $exception; $galat !== null; $galat = $galat->getPrevious()) {
            if (($galat instanceof QueryException || $galat instanceof PDOException) && in_array((int) ($galat->errorInfo[1] ?? 0), self::KODE, true)) {
                return true;
            }
        }

        return false;
    }
}
