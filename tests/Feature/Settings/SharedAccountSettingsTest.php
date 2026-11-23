<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reviewers share the seeded admin and demo logins, so no one signed in as
 * them may change the email or password or delete the account: one reviewer
 * could otherwise lock the others out. Settings keeps Appearance only.
 */
class SharedAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'seed.admin' => ['email' => 'admin@example.com', 'password' => 'admin-secret'],
            'seed.demo' => ['email' => 'demo@example.com', 'password' => 'demo-secret'],
        ]);
        $this->seed();
    }

    /**
     * The starter kit's account routes, all removed.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, string>}>
     */
    public static function accountRoutes(): array
    {
        return [
            'profile page' => ['get', '/settings/profile', []],
            'change name or email' => ['patch', '/settings/profile', ['name' => 'Locked out', 'email' => 'mine@example.com']],
            'delete account' => ['delete', '/settings/profile', ['password' => 'demo-secret']],
            'security page' => ['get', '/settings/security', []],
            'change password' => ['put', '/settings/password', [
                'current_password' => 'demo-secret',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ]],
        ];
    }

    /**
     * @param  array<string, string>  $data
     */
    #[DataProvider('accountRoutes')]
    public function test_seeded_users_cannot_change_their_account(string $method, string $uri, array $data): void
    {
        foreach (['admin@example.com', 'demo@example.com'] as $email) {
            $user = User::query()->where('email', $email)->firstOrFail();
            $password = $email === 'admin@example.com' ? 'admin-secret' : 'demo-secret';

            $this->actingAs($user)->{$method.'Json'}($uri, $data)->assertNotFound();

            $user->refresh();
            $this->assertSame($email, $user->email);
            $this->assertNotSame('Locked out', $user->name);
            $this->assertTrue(Hash::check($password, $user->password));
            $this->assertNotSoftDeleted($user);
        }
    }

    public function test_settings_opens_appearance(): void
    {
        $demo = User::query()->where('email', 'demo@example.com')->firstOrFail();

        $this->actingAs($demo)->get('/settings')->assertRedirect('/settings/appearance');
        $this->actingAs($demo)->get(route('appearance.edit'))->assertOk();
    }

    public function test_appearance_still_needs_a_sign_in(): void
    {
        $this->get(route('appearance.edit'))->assertRedirect(route('login'));
    }
}
