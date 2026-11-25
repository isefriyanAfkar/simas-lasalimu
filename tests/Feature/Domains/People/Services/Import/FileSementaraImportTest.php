<?php

namespace Tests\Feature\Domains\People\Services\Import;

use App\Domains\People\Services\ImportSiswaService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Temporary files (the error file and the template for download, the
 * sample import) live in simas.folder_sementara, a folder on the private
 * disk of the application, never in the system temporary folder: the error
 * file can hold NIKs, and the system folder is shared with other sites on
 * shared hosting. simas:bersihkan-import deletes what is left there after
 * a day.
 */
class FileSementaraImportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/folder-sementara-'.Str::lower(Str::random(8)));
        config(['simas.folder_sementara' => $this->folder]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    public function test_the_folder_is_on_the_private_disk_by_default(): void
    {
        $this->refreshApplication();

        $this->assertSame(storage_path('app/private/tmp'), config('simas.folder_sementara'));
    }

    public function test_a_temporary_file_is_made_in_that_folder_which_is_created_when_missing(): void
    {
        $this->assertDirectoryDoesNotExist($this->folder);

        $path = ImportSiswaService::fileSementaraBaru('galat-import');

        $this->assertFileExists($path);
        $this->assertSame(realpath($this->folder), realpath(dirname($path)));
    }

    public function test_no_file_is_made_when_the_folder_cannot_be_created(): void
    {
        File::ensureDirectoryExists(dirname($this->folder));
        file_put_contents($this->folder, 'bukan folder');
        $galat = null;

        try {
            ImportSiswaService::fileSementaraBaru('galat-import');
        } catch (RuntimeException $exception) {
            $galat = $exception->getMessage();
        } finally {
            unlink($this->folder);
        }

        $this->assertSame('Tidak bisa membuat file sementara.', $galat);
    }

    public function test_the_cleanup_deletes_temporary_files_older_than_a_day(): void
    {
        Queue::fake();
        File::ensureDirectoryExists($this->folder);
        file_put_contents($lama = $this->folder.DIRECTORY_SEPARATOR.'galDB12.tmp', 'x');
        touch($lama, now()->subHours(25)->getTimestamp());
        file_put_contents($baru = $this->folder.DIRECTORY_SEPARATOR.'temAB34.tmp', 'x');
        touch($baru, now()->subHours(23)->getTimestamp());

        $this->artisan('simas:bersihkan-import')
            ->expectsOutputToContain('File sementara dihapus: 1.')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($lama);
        $this->assertFileExists($baru);
    }

    /**
     * @return array<string, array{\Closure(): string}>
     */
    public static function folderDiLuarStorage(): array
    {
        return [
            'kosong' => [fn (): string => ''],
            'garis miring' => [fn (): string => '/'],
            'garis miring terbalik' => [fn (): string => '\\'],
            'folder temp sistem' => [fn (): string => sys_get_temp_dir()],
            'di samping storage' => [fn (): string => base_path('tmp')],
            'nama berawalan storage' => [fn (): string => storage_path().'-lain'],
            'keluar lewat ..' => [fn (): string => storage_path('app/../../luar')],
        ];
    }

    #[DataProvider('folderDiLuarStorage')]
    public function test_a_folder_outside_storage_is_refused_before_anything_is_made(\Closure $folder): void
    {
        config(['simas.folder_sementara' => $folder()]);
        $galat = null;

        try {
            ImportSiswaService::fileSementaraBaru('galat-import');
        } catch (RuntimeException $exception) {
            $galat = $exception->getMessage();
        }

        $this->assertSame(sprintf('Folder file sementara (simas.folder_sementara) harus berada di dalam %s, bukan "%s".', storage_path(), $folder()), $galat);
    }

    public function test_the_cleanup_refuses_a_folder_outside_storage_and_deletes_nothing(): void
    {
        Queue::fake();
        $luar = base_path('tmp-uji-'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists($luar);
        file_put_contents($lama = $luar.DIRECTORY_SEPARATOR.'lama.txt', 'x');
        touch($lama, now()->subDays(3)->getTimestamp());
        config(['simas.folder_sementara' => $luar]);
        $galat = null;

        try {
            app(ImportSiswaService::class)->bersihkan();
        } catch (RuntimeException $exception) {
            $galat = $exception->getMessage();
        } finally {
            $masihAda = is_file($lama);
            File::deleteDirectory($luar);
        }

        $this->assertSame(sprintf('Folder file sementara (simas.folder_sementara) harus berada di dalam %s, bukan "%s".', storage_path(), $luar), $galat);
        $this->assertTrue($masihAda);
    }

    public function test_a_folder_inside_storage_written_with_either_slash_is_accepted(): void
    {
        config(['simas.folder_sementara' => storage_path('app/private/tmp')]);

        $this->assertSame(storage_path('app/private/tmp'), ImportSiswaService::folderSementara());
    }

    public function test_the_cleanup_runs_when_the_folder_does_not_exist_yet(): void
    {
        Queue::fake();

        $this->assertSame(0, app(ImportSiswaService::class)->bersihkan()['sementara']);
        $this->assertDirectoryDoesNotExist($this->folder);
    }
}
