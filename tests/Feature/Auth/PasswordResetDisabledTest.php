<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * No password reset: accounts are seeded and the app sends no email, so a
 * reset link could never arrive.
 */
class PasswordResetDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_routes_do_not_exist(): void
    {
        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', ['email' => 'someone@example.com'])->assertNotFound();
        $this->get('/reset-password/some-token')->assertNotFound();
        $this->post('/reset-password', ['token' => 'x', 'email' => 'someone@example.com'])->assertNotFound();
    }

    public function test_login_page_offers_no_reset_link(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Login')
                ->missing('canResetPassword'));
    }
}
