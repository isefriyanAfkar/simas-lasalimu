<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The statuses a school starts with. Filled here, not in a seeder, so a
     * server has them right after `php artisan migrate`.
     *
     * @var list<string>
     */
    private const array ISI_AWAL = ['PNS', 'PPPK', 'GTT', 'PTT', 'GTBPNS'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('status_kepegawaian', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 50)->unique();
            $table->unsignedSmallInteger('urutan')->nullable();
            $table->boolean('status_aktif')->default(true)->index();
            $table->timestamps();
        });

        $sekarang = now();

        DB::table('status_kepegawaian')->insert(array_map(
            fn (string $nama, int $indeks): array => [
                'nama' => $nama,
                'urutan' => $indeks + 1,
                'status_aktif' => true,
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ],
            self::ISI_AWAL,
            array_keys(self::ISI_AWAL),
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('status_kepegawaian');
    }
};
