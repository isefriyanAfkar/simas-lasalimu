<?php

namespace Tests\Feature\Deployment;

use Tests\TestCase;

/**
 * docs/pemasangan.md runs `php artisan optimize` on the server. Its view step
 * fails when resources/views is missing from a fresh clone (git keeps no
 * empty folders), which would stop the deployment.
 */
class CacheProduksiTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->artisan('view:clear');

        parent::tearDown();
    }

    public function test_view_cache_succeeds_as_in_the_deployment_guide(): void
    {
        $this->artisan('view:cache')->assertExitCode(0);
    }
}
