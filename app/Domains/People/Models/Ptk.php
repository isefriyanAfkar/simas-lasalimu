<?php

namespace App\Domains\People\Models;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\JenisPtk;
use App\Domains\People\Enums\StatusPtk;
use App\Domains\People\Policies\PtkPolicy;
use Carbon\CarbonInterface;
use Database\Factories\PtkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A staff member (pendidik dan tenaga kependidikan) and the account they
 * belong to. The name and biodata live on the account (users); this row
 * holds the staff data. Written only through PtkService. Never deleted: a
 * staff member who leaves gets the status mutasi, pensiun or berhenti.
 *
 * The full name with titles ("Dra. Hafida, S.Pd., Gr.") is always read
 * through $ptk->nama_lengkap, or Ptk::namaLengkapDari() without a model.
 *
 * log_penonaktifan_akun_id is the activity log entry of the account
 * deactivation done by the last status change, if any; see
 * PtkService::aktifkanKembali().
 *
 * @property int $user_id
 * @property JenisPtk $jenis_ptk
 * @property int $status_kepegawaian_id
 * @property string|null $nip
 * @property string|null $nuptk
 * @property string|null $gelar_depan
 * @property string|null $gelar_belakang
 * @property string|null $kode
 * @property bool $sertifikasi
 * @property int|null $tahun_sertifikasi
 * @property StatusPtk $status
 * @property CarbonInterface|null $tanggal_keluar
 * @property string|null $keterangan_keluar
 * @property int|null $log_penonaktifan_akun_id
 * @property-read string $nama_lengkap
 * @property User $user
 * @property StatusKepegawaian $statusKepegawaian
 */
#[Table('ptk')]
#[Fillable([
    'user_id', 'jenis_ptk', 'status_kepegawaian_id', 'nip', 'nuptk', 'gelar_depan', 'gelar_belakang',
    'kode', 'sertifikasi', 'tahun_sertifikasi', 'status', 'tanggal_keluar', 'keterangan_keluar',
    'log_penonaktifan_akun_id',
])]
#[UseFactory(PtkFactory::class)]
#[UsePolicy(PtkPolicy::class)]
class Ptk extends Model
{
    /** @use HasFactory<PtkFactory> */
    use HasFactory, LogsActivity;

    /**
     * Fields logged by the trait on create and update. The status and the
     * exit fields are logged explicitly by PtkService.
     *
     * @var list<string>
     */
    public const array LOGGED_ATTRIBUTES = [
        'user_id', 'jenis_ptk', 'status_kepegawaian_id', 'nip', 'nuptk', 'gelar_depan',
        'gelar_belakang', 'kode', 'sertifikasi', 'tahun_sertifikasi',
    ];

    /**
     * "Dra. Hafida, S.Pd., Gr.": front title, name, then ", " and the back
     * titles. An empty title leaves no stray space or comma.
     */
    public static function namaLengkapDari(?string $gelarDepan, string $nama, ?string $gelarBelakang): string
    {
        $gelarDepan = trim((string) $gelarDepan);
        $gelarBelakang = trim((string) $gelarBelakang);
        $namaLengkap = trim($gelarDepan.' '.trim($nama));

        return $gelarBelakang === '' ? $namaLengkap : "{$namaLengkap}, {$gelarBelakang}";
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<StatusKepegawaian, $this>
     */
    public function statusKepegawaian(): BelongsTo
    {
        return $this->belongsTo(StatusKepegawaian::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('kepegawaian')
            ->logOnly(self::LOGGED_ATTRIBUTES)
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName): string => match ($eventName) {
                'created' => 'Data guru/staf dibuat',
                'updated' => 'Data guru/staf diubah',
                'deleted' => 'Data guru/staf dihapus',
                default => "Data guru/staf {$eventName}",
            });
    }

    /**
     * @return Attribute<string, never>
     */
    protected function namaLengkap(): Attribute
    {
        return Attribute::get(fn (): string => self::namaLengkapDari($this->gelar_depan, $this->user->name, $this->gelar_belakang));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jenis_ptk' => JenisPtk::class,
            'status' => StatusPtk::class,
            'sertifikasi' => 'boolean',
            'tahun_sertifikasi' => 'integer',
            'tanggal_keluar' => 'date',
            'log_penonaktifan_akun_id' => 'integer',
        ];
    }
}
