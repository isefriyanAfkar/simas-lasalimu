<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Students. One row per user account, whose username is the NIS.
     * nisn is unique when filled; MariaDB allows any number of NULLs in a
     * unique index.
     */
    public function up(): void
    {
        Schema::create('siswa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('nis', 20)->unique();
            $table->string('nisn', 10)->nullable()->unique();
            $table->date('tanggal_masuk')->nullable();
            $table->string('asal_sekolah', 150)->nullable();
            $table->string('status', 15)->default('aktif')->index();
            $table->date('tanggal_keluar')->nullable();
            $table->string('keterangan_keluar', 500)->nullable();
            $table->unsignedBigInteger('log_penonaktifan_akun_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('siswa');
    }
};
