<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sign-up is closed: the app is on a public URL and Pixelforge's 500-a-day
 * limit is shared across the whole account, so anyone who could register
 * could spend it. Accounts come from the seeder instead.
 */
class RegistrationClosedTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_page_does_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_register_post_does_not_exist(): void
    {
        $this->post('/register', [
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }
}
