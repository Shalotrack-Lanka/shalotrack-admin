<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_a_guest_visiting_the_home_page_is_sent_to_the_login_screen(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
