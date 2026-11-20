<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One student import from an Excel file (ImportSiswaService): who
     * uploaded it, for which school year, its status, and the result of the
     * last check (ringkasan, galat, pindah). galat never holds a NIK or the
     * name of whoever holds one. path is the uploaded file on the private
     * disk, NULL once the file is deleted.
     */
    public function up(): void
    {
        Schema::create('import_siswa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran')->restrictOnDelete();
            $table->string('nama_file', 255);
            $table->string('path', 255)->nullable();
            $table->string('status', 20)->index();
            $table->json('ringkasan')->nullable();
            $table->json('galat')->nullable();
            $table->json('pindah')->nullable();
            $table->string('pesan', 1000)->nullable();
            $table->timestamp('diproses_sejak')->nullable();
            $table->timestamp('selesai_pada')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_siswa');
    }
};
