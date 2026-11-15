<?php

namespace Tests\Feature\Routes;

use Tests\TestCase;

class HalamanAwalTest extends TestCase
{
    public function test_root_redirects_to_the_panel_login_page(): void
    {
        $this->get('/')->assertRedirect('/admin/login');
    }
}
