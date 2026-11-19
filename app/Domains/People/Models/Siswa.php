<?php

namespace App\Domains\People\Models;

use App\Domains\Academic\Models\PenempatanRombel;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Policies\SiswaPolicy;
use Carbon\CarbonInterface;
use Database\Factories\SiswaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
     * Order by NIS as a number ("9999" before "10000"): the NIS is 4 to 20
     * digits stored as text, so it is padded to 20 with leading zeros. Lists
     * and the bulk placement use this one order.
     *
     * @param  Builder<Siswa>  $query
     */
    #[Scope]
    protected function urutanNis(Builder $query, string $direction = 'asc'): void
    {
        $query->orderByRaw("LPAD(siswa.nis, 20, '0') ".($direction === 'desc' ? 'desc' : 'asc'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The student's parents and guardians, with their relation and which one
     * is the main contact.
     *
     * @return HasMany<SiswaWali, $this>
     */
    public function siswaWali(): HasMany
    {
        return $this->hasMany(SiswaWali::class);
    }

    /**
     * The student's rombel per school year. The rombel in the active school
     * year is read through RombelSiswa.
     *
     * @return HasMany<PenempatanRombel, $this>
     */
    public function penempatanRombel(): HasMany
    {
        return $this->hasMany(PenempatanRombel::class);
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
