<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * There's no public landing page: sign-up is closed.
 */
class HomeRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_go_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('generations.create'))->assertRedirect(route('login'));
        $this->get(route('generations.index'))->assertRedirect(route('login'));
    }

    public function test_signed_in_users_go_to_generate(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/')->assertRedirect(route('generations.create'));
        $this->get(route('dashboard'))->assertRedirect(route('generations.create'));
    }
}
