<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One lock row per kind of identity (KunciIdentitas): siswa,
     * wali_murid, akun. Writes of that identity lock the row first, so they
     * queue behind each other without locking the data tables whole. The
     * rows are never deleted or changed.
     */
    public function up(): void
    {
        Schema::create('kunci_identitas', function (Blueprint $table) {
            $table->string('jenis', 20)->primary();
        });

        DB::table('kunci_identitas')->insert([
            ['jenis' => 'siswa'],
            ['jenis' => 'wali_murid'],
            ['jenis' => 'akun'],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kunci_identitas');
    }
};
