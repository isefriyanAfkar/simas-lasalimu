<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * nik holds the NIK encrypted with APP_KEY (Laravel's `encrypted` cast),
     * so it is TEXT and cannot be searched. nik_hash holds its HMAC-SHA256
     * (keyed by NIK_HMAC_KEY); searching and the uniqueness check use it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('nik')->nullable()->after('status_aktif');
            $table->char('nik_hash', 64)->nullable()->unique()->after('nik');
            $table->string('jenis_kelamin', 10)->nullable()->after('nik_hash');
            $table->string('tempat_lahir', 100)->nullable()->after('jenis_kelamin');
            $table->date('tanggal_lahir')->nullable()->after('tempat_lahir');
            $table->string('agama', 20)->nullable()->after('tanggal_lahir');
            $table->text('alamat')->nullable()->after('agama');
            $table->string('no_hp', 15)->nullable()->after('alamat');
            $table->string('foto')->nullable()->after('no_hp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['nik_hash']);
            $table->dropColumn(['nik', 'nik_hash', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir', 'agama', 'alamat', 'no_hp', 'foto']);
        });
    }
};
