<?php

namespace App\Domains\People\Models;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Policies\SiswaPolicy;
use Carbon\CarbonInterface;
use Database\Factories\SiswaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A student and the account they belong to. The name and biodata live on the
 * account (users), whose username is always the NIS; this row holds the
 * student data. Written only through SiswaService. Never deleted: a student
 * who leaves gets the status lulus, mutasi_keluar or keluar.
 *
 * log_penonaktifan_akun_id is the activity log entry of the account
 * deactivation done by the last status change, if any; see
 * SiswaService::aktifkanKembali().
 *
 * @property int $user_id
 * @property string $nis
 * @property string|null $nisn
 * @property CarbonInterface|null $tanggal_masuk
 * @property string|null $asal_sekolah
 * @property StatusSiswa $status
 * @property CarbonInterface|null $tanggal_keluar
 * @property string|null $keterangan_keluar
 * @property int|null $log_penonaktifan_akun_id
 * @property User $user
 */
#[Table('siswa')]
#[Fillable([
    'user_id', 'nis', 'nisn', 'tanggal_masuk', 'asal_sekolah', 'status', 'tanggal_keluar',
    'keterangan_keluar', 'log_penonaktifan_akun_id',
])]
#[UseFactory(SiswaFactory::class)]
#[UsePolicy(SiswaPolicy::class)]
class Siswa extends Model
{
    /** @use HasFactory<SiswaFactory> */
    use HasFactory, LogsActivity;

    /**
     * Fields logged by the trait on create and update. The status and the
     * exit fields are logged explicitly by SiswaService.
     *
     * @var list<string>
     */
    public const array LOGGED_ATTRIBUTES = ['user_id', 'nis', 'nisn', 'tanggal_masuk', 'asal_sekolah'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('kesiswaan')
            ->logOnly(self::LOGGED_ATTRIBUTES)
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName): string => match ($eventName) {
                'created' => 'Data siswa dibuat',
                'updated' => 'Data siswa diubah',
                'deleted' => 'Data siswa dihapus',
                default => "Data siswa {$eventName}",
            });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StatusSiswa::class,
            'tanggal_masuk' => 'date:Y-m-d',
            'tanggal_keluar' => 'date',
            'log_penonaktifan_akun_id' => 'integer',
        ];
    }
}
