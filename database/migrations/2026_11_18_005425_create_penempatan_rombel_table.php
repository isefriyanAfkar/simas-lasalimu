<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which rombel a student is in, per school year. tahun_ajaran_id is a copy
     * of the rombel's school year, guarded by a foreign key on (rombel_id,
     * tahun_ajaran_id): the database refuses a placement whose school year
     * differs from its rombel's, and a change of the rombel's school year
     * while it has placements. The unique index on (siswa_id,
     * tahun_ajaran_id) keeps a student in at most one rombel per school year.
     */
    public function up(): void
    {
        Schema::create('penempatan_rombel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswa')->restrictOnDelete();
            $table->foreignId('rombel_id')->constrained('rombel')->restrictOnDelete();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['siswa_id', 'tahun_ajaran_id']);
            $table->foreign(['rombel_id', 'tahun_ajaran_id'], 'penempatan_rombel_rombel_tahun_ajaran_foreign')
                ->references(['id', 'tahun_ajaran_id'])
                ->on('rombel')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('penempatan_rombel');
    }
};
