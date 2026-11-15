<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('sekolah.nama_sekolah', null);
        $this->migrator->add('sekolah.npsn', null);
        $this->migrator->add('sekolah.jenjang', null);
        $this->migrator->add('sekolah.alamat', null);
        $this->migrator->add('sekolah.telepon', null);
        $this->migrator->add('sekolah.email', null);
        $this->migrator->add('sekolah.logo', null);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('sekolah.nama_sekolah');
        $this->migrator->deleteIfExists('sekolah.npsn');
        $this->migrator->deleteIfExists('sekolah.jenjang');
        $this->migrator->deleteIfExists('sekolah.alamat');
        $this->migrator->deleteIfExists('sekolah.telepon');
        $this->migrator->deleteIfExists('sekolah.email');
        $this->migrator->deleteIfExists('sekolah.logo');
    }
};
