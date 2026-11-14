<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->after('name');
            $table->boolean('status_aktif')->default(true)->after('password');
            $table->string('email')->nullable()->change();
        });

        $this->backfillUsernames();

        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable(false)->change();
            $table->unique('username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'status_aktif']);
            $table->string('email')->nullable(false)->change();
        });
    }

    /**
     * Give existing users a username from the part of their email before "@".
     * Collisions get a numeric suffix: budi, budi2, budi3.
     */
    private function backfillUsernames(): void
    {
        $takenUsernames = [];

        DB::table('users')->orderBy('id')->select(['id', 'email'])->each(function (object $user) use (&$takenUsernames): void {
            $baseUsername = Str::lower(trim(Str::before((string) $user->email, '@')));

            if ($baseUsername === '') {
                $baseUsername = 'user'.$user->id;
            }

            $username = $baseUsername;
            $suffix = 2;

            while (isset($takenUsernames[$username])) {
                $username = $baseUsername.$suffix;
                $suffix++;
            }

            $takenUsernames[$username] = true;

            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        });
    }
};
