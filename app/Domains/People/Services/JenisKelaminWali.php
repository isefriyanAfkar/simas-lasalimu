<?php

namespace App\Domains\People\Services;

use App\Domains\People\Enums\HubunganWali;
use App\Domains\People\Models\SiswaWali;
use App\Domains\People\Models\WaliMurid;

/**
 * The gender of a parent checked against every ayah or ibu relation they
 * have: an ayah is male, an ibu female. Used wherever a parent's gender may
 * change (the Wali Murid screen and Kelola Pengguna), so both apply the same
 * rule.
 */
final class JenisKelaminWali
{
    /**
     * An empty jenis_kelamin is filled in from such a relation; one that
     * contradicts it is a field error naming the student. Reads the
     * relations with a lock: in the lock order S → W → users → R this is the
     * last step, so the caller must already hold the identity locks, the
     * parent's row and the parent's account row (UserService::update() locks
     * the account row before calling this).
     *
     * @param  array<string, mixed>  $data
     * @return array{array<string, mixed>, array<string, list<string>>}
     */
    public static function sesuaikan(WaliMurid $wali, array $data): array
    {
        $relasi = SiswaWali::query()
            ->with('siswa.user')
            ->where('wali_murid_id', $wali->getKey())
            ->whereIn('hubungan', [HubunganWali::Ayah->value, HubunganWali::Ibu->value])
            ->lockForUpdate()
            ->get();

        foreach ($relasi as $siswaWali) {
            $wajib = $siswaWali->hubungan->jenisKelamin();

            if ($wajib === null) {
                continue;
            }

            if (blank($data['jenis_kelamin'] ?? null)) {
                $data['jenis_kelamin'] = $wajib->value;

                continue;
            }

            if ($data['jenis_kelamin'] !== $wajib->value) {
                return [$data, ['jenis_kelamin' => [sprintf(
                    '%s adalah %s %s, jadi harus %s.',
                    $wali->user->name,
                    mb_strtolower($siswaWali->hubungan->label()),
                    $siswaWali->siswa->user->name,
                    mb_strtolower($wajib->label()),
                )]]];
            }
        }

        return [$data, []];
    }
}
