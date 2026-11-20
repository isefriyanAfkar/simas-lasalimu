<?php

namespace Tests;

use App\Domains\Foundation\Services\KunciSekaliPerTransaksi;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase opens and rolls back the transaction around each test
     * with the event dispatcher switched off, so KunciSekaliPerTransaksi
     * never sees it end; start every test without marks.
     */
    protected function setUp(): void
    {
        KunciSekaliPerTransaksi::lupakanSemua();

        parent::setUp();
    }
}
