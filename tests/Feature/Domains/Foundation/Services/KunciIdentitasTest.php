<?php

namespace Tests\Feature\Domains\Foundation\Services;

use App\Domains\Foundation\Enums\JenisKunciIdentitas;
use App\Domains\Foundation\Services\KunciIdentitas;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * The lock rows per kind of identity: three rows from the migration, always
 * locked in the order siswa → wali_murid → akun, only inside a transaction.
 */
class KunciIdentitasTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_migration_makes_exactly_the_three_lock_rows(): void
    {
        $this->assertEqualsCanonicalizing(['siswa', 'wali_murid', 'akun'], DB::table('kunci_identitas')->pluck('jenis')->all());
    }

    public function test_the_rows_are_locked_in_the_fixed_order_whatever_the_order_asked(): void
    {
        $dikunci = $this->kunciYangDiambil(fn () => KunciIdentitas::kunci(JenisKunciIdentitas::Akun, JenisKunciIdentitas::Siswa, JenisKunciIdentitas::WaliMurid, JenisKunciIdentitas::Akun));

        $this->assertSame(['siswa', 'wali_murid', 'akun'], $dikunci);
        $this->assertSame(['akun'], $this->kunciYangDiambil(fn () => KunciIdentitas::kunci(JenisKunciIdentitas::Akun)));
    }

    public function test_locking_outside_a_transaction_is_refused(): void
    {
        DB::table('kunci_identitas')->count();
        $this->assertSame(1, DB::transactionLevel(), 'The lazy test transaction is open.');
        DB::rollBack();

        try {
            KunciIdentitas::kunci(JenisKunciIdentitas::Akun);
            $this->fail('Locked outside a transaction.');
        } catch (LogicException $exception) {
            $this->assertSame('KunciIdentitas must run inside a transaction.', $exception->getMessage());
        } finally {
            DB::beginTransaction();
        }
    }

    public function test_a_missing_lock_row_is_refused_instead_of_locking_nothing(): void
    {
        DB::table('kunci_identitas')->where('jenis', 'wali_murid')->delete();

        try {
            DB::transaction(fn () => KunciIdentitas::kunci(JenisKunciIdentitas::WaliMurid));
            $this->fail('A missing lock row was accepted.');
        } catch (LogicException $exception) {
            $this->assertSame('The lock row "wali_murid" is missing from kunci_identitas. Run the migrations.', $exception->getMessage());
        }
    }

    /**
     * The jenis of every kunci_identitas row locked while $aksi runs, in a
     * transaction, in order.
     *
     * @return list<string>
     */
    private function kunciYangDiambil(\Closure $aksi): array
    {
        $dikunci = [];

        DB::listen(function (QueryExecuted $query) use (&$dikunci): void {
            if (str_contains($query->sql, '`kunci_identitas`') && str_ends_with($query->sql, 'for update')) {
                $dikunci[] = (string) $query->bindings[0];
            }
        });

        DB::transaction($aksi);

        return $dikunci;
    }
}
