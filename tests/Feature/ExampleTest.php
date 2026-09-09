<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response(): void
    {
        // The port's `/` is the sign-in gate: a guest is sent to /login
        // (routes/web.php), matching the reference's protected shell.
        $this->get(route('home'))->assertRedirect(route('login'));

        $response = $this->get(route('login'));

        $response->assertOk();
    }
}
