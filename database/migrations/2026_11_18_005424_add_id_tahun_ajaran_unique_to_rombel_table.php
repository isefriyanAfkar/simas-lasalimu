<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A unique index on (id, tahun_ajaran_id), so penempatan_rombel can
     * reference both columns with one foreign key: the database then refuses
     * a placement whose school year differs from its rombel's. id alone is
     * already unique, so this index never rejects a row.
     */
    public function up(): void
    {
        Schema::table('rombel', function (Blueprint $table) {
            $table->unique(['id', 'tahun_ajaran_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rombel', function (Blueprint $table) {
            $table->dropUnique(['id', 'tahun_ajaran_id']);
        });
    }
};
