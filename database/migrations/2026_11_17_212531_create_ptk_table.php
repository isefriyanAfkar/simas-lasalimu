<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff (pendidik dan tenaga kependidikan). One row per user account.
     * nip, nuptk and kode are unique when filled; MariaDB allows any number
     * of NULLs in a unique index.
     */
    public function up(): void
    {
        Schema::create('ptk', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('jenis_ptk', 10)->index();
            $table->foreignId('status_kepegawaian_id')->constrained('status_kepegawaian')->restrictOnDelete();
            $table->string('nip', 18)->nullable()->unique();
            $table->string('nuptk', 16)->nullable()->unique();
            $table->string('gelar_depan', 50)->nullable();
            $table->string('gelar_belakang', 100)->nullable();
            $table->string('kode', 10)->nullable()->unique();
            $table->boolean('sertifikasi')->default(false)->index();
            $table->unsignedSmallInteger('tahun_sertifikasi')->nullable();
            $table->string('status', 10)->default('aktif')->index();
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
        Schema::dropIfExists('ptk');
    }
};
