<?php

namespace Tests\Feature\Domains\Foundation\Services;

use App\Domains\Foundation\Services\KunciSekaliPerTransaksi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * A queue worker (queue:work) runs many jobs in one process. A mark "this
 * lock is held" left by one job, for example because a transaction ended
 * without its events, must never make the next job skip its lock. Two
 * layers clear the marks, independent of the transaction events:
 * - the marks live in a scoped instance, which the worker forgets before
 *   every job it picks up (daemon mode);
 * - every job start and end (processing, processed, exception, failed)
 *   forgets them, also with --once and the sync queue.
 *
 * The transaction around each test (RefreshDatabase, opened before the test
 * body so it never starts inside a job) is the level
 * below every job's own transactions, so a job that ends a transaction
 * without its events really leaves its mark behind.
 */
class KunciSekaliPerTransaksiAntreanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        PeriksaKunciDiambil::$diambil = null;
    }

    public function test_a_worker_takes_the_lock_again_in_the_next_job(): void
    {
        TinggalkanPenandaKunci::dispatch(false);
        PeriksaKunciDiambil::dispatch();

        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();

        $this->assertTrue(PeriksaKunciDiambil::$diambil);
    }

    public function test_after_a_failed_job_the_next_job_takes_the_lock_again(): void
    {
        TinggalkanPenandaKunci::dispatch(true);
        PeriksaKunciDiambil::dispatch();

        // --once does not forget the scoped instances: only the job events
        // clear the marks here.
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);

        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertTrue(PeriksaKunciDiambil::$diambil);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function peristiwaJob(): array
    {
        return [
            'job mulai' => [JobProcessing::class],
            'job selesai' => [JobProcessed::class],
            'job melempar exception' => [JobExceptionOccurred::class],
            'job gagal' => [JobFailed::class],
        ];
    }

    /**
     * @param  class-string  $peristiwa
     */
    #[DataProvider('peristiwaJob')]
    public function test_every_job_start_and_end_forgets_the_marks(string $peristiwa): void
    {
        TinggalkanPenandaKunci::penanda();

        $job = $this->createStub(Job::class);
        event(match ($peristiwa) {
            JobProcessing::class => new JobProcessing('database', $job),
            JobProcessed::class => new JobProcessed('database', $job),
            JobExceptionOccurred::class => new JobExceptionOccurred('database', $job, new RuntimeException('gagal')),
            JobFailed::class => new JobFailed('database', $job, new RuntimeException('gagal')),
        });

        $this->assertTrue(PeriksaKunciDiambil::periksa());
    }

    public function test_forgetting_the_scoped_instances_forgets_the_marks(): void
    {
        TinggalkanPenandaKunci::penanda();

        $this->app->forgetScopedInstances();

        $this->assertTrue(PeriksaKunciDiambil::periksa());
    }

    public function test_a_left_mark_is_skipped_without_either_layer(): void
    {
        TinggalkanPenandaKunci::penanda();

        $this->assertFalse(PeriksaKunciDiambil::periksa());
    }
}

/**
 * Takes the lock in a transaction that ends without its events, so the mark
 * stays behind; then fails when asked to.
 */
class TinggalkanPenandaKunci implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public bool $gagal) {}

    public function handle(): void
    {
        self::penanda();

        if ($this->gagal) {
            throw new RuntimeException('Job gagal setelah meninggalkan penanda.');
        }
    }

    public static function penanda(): void
    {
        $koneksi = DB::connection();
        $events = $koneksi->getEventDispatcher();
        $koneksi->unsetEventDispatcher();

        try {
            $koneksi->beginTransaction();
            KunciSekaliPerTransaksi::kunci('uji', fn () => null);
            $koneksi->rollBack();
        } finally {
            if ($events !== null) {
                $koneksi->setEventDispatcher($events);
            }
        }
    }
}

/**
 * Records whether its own transaction really ran the lock.
 */
class PeriksaKunciDiambil implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public static ?bool $diambil = null;

    public function handle(): void
    {
        self::$diambil = self::periksa();
    }

    public static function periksa(): bool
    {
        $diambil = false;

        DB::transaction(function () use (&$diambil): void {
            KunciSekaliPerTransaksi::kunci('uji', function () use (&$diambil): void {
                $diambil = true;
            });
        });

        return $diambil;
    }
}
