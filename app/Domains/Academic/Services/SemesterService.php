<?php

namespace App\Domains\Academic\Services;

use App\Domains\Academic\Enums\AksiAkademik;
use App\Domains\Academic\Enums\JenisSemester;
use App\Domains\Academic\Models\Semester;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Foundation\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Semesters are never added or removed by hand (TahunAjaranService creates
 * and deletes them with their school year); only their dates change here.
 *
 * Rules:
 * - a semester lies inside its school year, and ends on or after its start
 * - Ganjil ends before Genap starts
 * - at most one semester is active, and it belongs to the active school year
 */
class SemesterService
{
    /**
     * @param  array{tanggal_mulai: string, tanggal_selesai: string}  $data
     *
     * @throws ValidationException
     */
    public function updateDates(Semester $semester, array $data): Semester
    {
        return DB::transaction(function () use ($semester, $data): Semester {
            $tahunAjaran = TahunAjaran::query()->lockForUpdate()->findOrFail($semester->tahun_ajaran_id);
            $pasangan = $tahunAjaran->semester()->whereKeyNot($semester->getKey())->firstOrFail();

            $validator = Validator::make($data, [
                'tanggal_mulai' => ['required', 'date_format:Y-m-d'],
                'tanggal_selesai' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_mulai'],
            ], [
                'tanggal_selesai.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            ], [
                'tanggal_mulai' => 'tanggal mulai',
                'tanggal_selesai' => 'tanggal selesai',
            ]);

            $validator->after(function (ValidatorInstance $validator) use ($semester, $tahunAjaran, $pasangan, $data): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $mulai = (string) $data['tanggal_mulai'];
                $selesai = (string) $data['tanggal_selesai'];

                if ($mulai < $tahunAjaran->tanggal_mulai->toDateString()) {
                    $validator->errors()->add('tanggal_mulai', 'Tanggal mulai semester tidak boleh sebelum tanggal mulai tahun ajaran ('.$tahunAjaran->tanggal_mulai->toDateString().').');
                }

                if ($selesai > $tahunAjaran->tanggal_selesai->toDateString()) {
                    $validator->errors()->add('tanggal_selesai', 'Tanggal selesai semester tidak boleh sesudah tanggal selesai tahun ajaran ('.$tahunAjaran->tanggal_selesai->toDateString().').');
                }

                if ($semester->jenis === JenisSemester::Ganjil && $selesai >= $pasangan->tanggal_mulai->toDateString()) {
                    $validator->errors()->add('tanggal_selesai', 'Semester Ganjil harus selesai sebelum semester Genap mulai ('.$pasangan->tanggal_mulai->toDateString().').');
                }

                if ($semester->jenis === JenisSemester::Genap && $mulai <= $pasangan->tanggal_selesai->toDateString()) {
                    $validator->errors()->add('tanggal_mulai', 'Semester Genap harus mulai sesudah semester Ganjil selesai ('.$pasangan->tanggal_selesai->toDateString().').');
                }
            });

            $semester->update($validator->validate());

            return $semester;
        });
    }

    /**
     * @throws PelanggaranAturanAkademik
     */
    public function activate(User $pelaku, Semester $semester): Semester
    {
        return DB::transaction(function () use ($pelaku, $semester): Semester {
            $tahunAjaran = TahunAjaran::query()->lockForUpdate()->findOrFail($semester->tahun_ajaran_id);
            $semester->refresh();

            if (! $tahunAjaran->status_aktif) {
                throw PelanggaranAturanAkademik::semesterBukanMilikTahunAjaranAktif();
            }

            if ($semester->status_aktif) {
                return $semester;
            }

            Semester::query()->where('status_aktif', true)->update(['status_aktif' => false]);
            $semester->update(['status_aktif' => true]);
            TahunAjaranService::logActivation(AksiAkademik::SemesterDiaktifkan, $pelaku, $semester);

            return $semester;
        });
    }
}
