<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which parents and guardians a student has. The database keeps, per
     * student:
     * - each guardian at most once (unique siswa_id, wali_murid_id);
     * - at most one ayah and one ibu: penanda_ayah_ibu holds the relation for
     *   ayah and ibu and NULL for wali, and MariaDB allows any number of NULLs
     *   in a unique index;
     * - at most one main guardian: penanda_utama is 1 while utama and NULL
     *   otherwise.
     * Both markers are virtual columns, so they always follow hubungan and
     * utama. SiswaWaliService checks the same rules first, under a lock.
     */
    public function up(): void
    {
        Schema::create('siswa_wali', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswa')->restrictOnDelete();
            $table->foreignId('wali_murid_id')->constrained('wali_murid')->restrictOnDelete();
            $table->string('hubungan', 10);
            $table->boolean('utama')->default(false);
            $table->string('penanda_ayah_ibu', 10)->nullable()->virtualAs("if(hubungan in ('ayah', 'ibu'), hubungan, null)");
            $table->boolean('penanda_utama')->nullable()->virtualAs('if(utama, 1, null)');
            $table->timestamps();

            $table->unique(['siswa_id', 'wali_murid_id']);
            $table->unique(['siswa_id', 'penanda_ayah_ibu']);
            $table->unique(['siswa_id', 'penanda_utama']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('siswa_wali');
    }
};
