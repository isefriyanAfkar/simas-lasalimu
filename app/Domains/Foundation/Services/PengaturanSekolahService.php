<?php

namespace App\Domains\Foundation\Services;

use App\Domains\Foundation\Enums\AksiPengaturan;
use App\Domains\Foundation\Enums\Jenjang;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Settings\PengaturanSekolah;
use BackedEnum;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * The only place that writes the school settings.
 *
 * Rules:
 * - only a super_admin may save them
 * - nama_sekolah is required when saving; npsn, when filled, is exactly 8 digits
 * - every change is logged (log "pengaturan") with old and new values, in the
 *   same transaction as the save
 * - a replaced or removed logo file is deleted, but only after the transaction
 *   commits, so a failed save never loses the current logo
 *
 * Reading for the panel (namaPanel, urlLogo) never throws: before the settings
 * table exists, for example during the first `php artisan migrate` on a new
 * server, they return null and the panel falls back to APP_NAME and no logo.
 */
class PengaturanSekolahService
{
    public const string LOGO_DISK = 'public';

    public const string LOGO_DIRECTORY = 'logo-sekolah';

    private const string LOG_NAME = 'pengaturan';

    /**
     * @var list<string>
     */
    private const array FIELDS = ['nama_sekolah', 'npsn', 'jenjang', 'alamat', 'telepon', 'email', 'logo'];

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function simpan(User $pelaku, array $data): PengaturanSekolah
    {
        if (! $pelaku->isSuperAdmin()) {
            throw new AuthorizationException('Hanya super admin yang boleh mengubah pengaturan sekolah.');
        }

        $validated = $this->validate($data);

        return DB::transaction(function () use ($pelaku, $validated): PengaturanSekolah {
            $settings = $this->settings();
            $before = $this->snapshot($settings);

            $settings->fill([
                ...$validated,
                'jenjang' => filled($validated['jenjang']) ? Jenjang::from($validated['jenjang']) : null,
            ]);
            $settings->save();

            $after = $this->snapshot($settings);
            $changed = array_filter($after, fn (mixed $value, string $key): bool => $value !== $before[$key], ARRAY_FILTER_USE_BOTH);

            if ($changed !== []) {
                activity(self::LOG_NAME)
                    ->causedBy($pelaku)
                    ->event(AksiPengaturan::SekolahDiubah->value)
                    ->withChanges(['attributes' => $changed, 'old' => array_intersect_key($before, $changed)])
                    ->log(AksiPengaturan::SekolahDiubah->label());
            }

            $oldLogo = $before['logo'];

            if ($oldLogo !== null && $oldLogo !== $after['logo']) {
                DB::afterCommit(fn () => Storage::disk(self::LOGO_DISK)->delete($oldLogo));
            }

            return $settings;
        });
    }

    /**
     * The school name for the panel brand, or null to fall back to APP_NAME.
     */
    public function namaPanel(): ?string
    {
        return $this->readSafely(fn (PengaturanSekolah $settings): ?string => filled($settings->nama_sekolah) ? $settings->nama_sekolah : null);
    }

    public function urlLogo(): ?string
    {
        return $this->readSafely(fn (PengaturanSekolah $settings): ?string => filled($settings->logo)
            ? Storage::disk(self::LOGO_DISK)->url($settings->logo)
            : null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{nama_sekolah: string, npsn: ?string, jenjang: ?string, alamat: ?string, telepon: ?string, email: ?string, logo: ?string}
     */
    private function validate(array $data): array
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));

        /** @var array{nama_sekolah: string, npsn?: ?string, jenjang?: ?string, alamat?: ?string, telepon?: ?string, email?: ?string, logo?: ?string} $validated */
        $validated = Validator::make($data, [
            'nama_sekolah' => ['required', 'string', 'max:255'],
            'npsn' => ['nullable', 'string', 'regex:/^\d{8}$/'],
            'jenjang' => ['nullable', Rule::enum(Jenjang::class)],
            'alamat' => ['nullable', 'string', 'max:1000'],
            'telepon' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'string', 'max:255'],
        ], [
            'npsn.regex' => 'NPSN harus terdiri dari tepat 8 digit angka.',
        ], [
            'nama_sekolah' => 'nama sekolah',
            'npsn' => 'NPSN',
        ])->validate();

        return [
            'nama_sekolah' => $validated['nama_sekolah'],
            'npsn' => $validated['npsn'] ?? null,
            'jenjang' => $validated['jenjang'] ?? null,
            'alamat' => $validated['alamat'] ?? null,
            'telepon' => $validated['telepon'] ?? null,
            'email' => $validated['email'] ?? null,
            'logo' => $validated['logo'] ?? null,
        ];
    }

    /**
     * Plain values (enums as their value) for comparing and logging.
     *
     * @return array<string, scalar|null>
     */
    private function snapshot(PengaturanSekolah $settings): array
    {
        $values = [];

        foreach (self::FIELDS as $field) {
            $value = $settings->{$field};
            $values[$field] = $value instanceof BackedEnum ? $value->value : $value;
        }

        return $values;
    }

    /**
     * @template T
     *
     * @param  callable(PengaturanSekolah): T  $read
     * @return T|null
     */
    private function readSafely(callable $read): mixed
    {
        try {
            return $read($this->settings());
        } catch (QueryException|MissingSettings) {
            return null;
        }
    }

    private function settings(): PengaturanSekolah
    {
        return app(PengaturanSekolah::class);
    }
}
