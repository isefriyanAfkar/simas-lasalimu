<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Second layer for "at most one active school year / semester": the services
 * already enforce it under lockForUpdate; this makes the database refuse a
 * second active row too. penanda_aktif is 1 while active and NULL otherwise,
 * and a unique index allows any number of NULLs.
 */
return new class extends Migration
{
    private const array TABLES = ['tahun_ajaran', 'semester'];

    public function up(): void
    {
        // MariaDB DDL is not transactional: check first, so a failure cannot
        // leave the column added without its index.
        foreach (self::TABLES as $tabel) {
            if (DB::table($tabel)->where('status_aktif', true)->count() > 1) {
                throw new RuntimeException("Lebih dari satu baris aktif di {$tabel}; rapikan dulu sebelum migrate.");
            }
        }

        // Each piece is added only when missing, so a run that stopped halfway
        // can simply be run again.
        foreach (self::TABLES as $tabel) {
            $adaKolom = Schema::hasColumn($tabel, 'penanda_aktif');
            $adaIndeks = Schema::hasIndex($tabel, "{$tabel}_penanda_aktif_unique", 'unique');

            Schema::table($tabel, function (Blueprint $table) use ($adaKolom, $adaIndeks): void {
                if (! $adaKolom) {
                    $table->boolean('penanda_aktif')
                        ->nullable()
                        ->virtualAs('if(status_aktif, 1, null)')
                        ->after('status_aktif');
                }

                if (! $adaIndeks) {
                    $table->unique('penanda_aktif');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tabel) {
            Schema::table($tabel, function (Blueprint $table) use ($tabel): void {
                $table->dropUnique("{$tabel}_penanda_aktif_unique");
                $table->dropColumn('penanda_aktif');
            });
        }
    }
};
