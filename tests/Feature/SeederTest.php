<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'seed.admin' => ['email' => 'Admin@Example.com', 'password' => 'admin-secret'],
            'seed.demo' => ['email' => 'demo@example.com', 'password' => 'demo-secret'],
        ]);
    }

    public function test_creates_an_admin_and_a_demo_user_with_five_dollars_each(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $demo = User::query()->where('email', 'demo@example.com')->firstOrFail();

        $this->assertTrue($admin->is_admin);
        $this->assertFalse($demo->is_admin);
        $this->assertTrue(Hash::check('demo-secret', $demo->password));
        $this->assertNotNull($demo->email_verified_at);
        $this->assertSame(5_000_000_000, app(LedgerService::class)->available($admin));
        $this->assertSame(5_000_000_000, app(LedgerService::class)->available($demo));
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(2, User::query()->count());
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_skips_an_account_whose_credentials_are_missing(): void
    {
        config(['seed.demo' => ['email' => null, 'password' => null]]);

        $this->seed();

        $this->assertSame(1, User::query()->count());
    }

    public function test_seeded_users_can_log_in(): void
    {
        $this->seed();

        $this->post(route('login.store'), ['email' => 'demo@example.com', 'password' => 'demo-secret']);

        $this->assertAuthenticated();
    }
}
