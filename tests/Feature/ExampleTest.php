<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_sees_the_public_landing_page(): void
    {
        // `/` is the public marketing landing for a guest, with a Sign In link
        // to /login (routes/web.php). A signed-in account skips it for the app.
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('BestWorkflows')
            ->assertSee(route('login'), false);

        $this->get(route('login'))->assertOk();
    }

    public function test_a_signed_in_account_is_taken_into_the_app(): void
    {
        $this->actingAs(User::factory()->create());

        // Not the landing — the shell's module landing (or "no modules" card).
        $this->get(route('home'))->assertDontSee('Request Pilot');
    }
}
