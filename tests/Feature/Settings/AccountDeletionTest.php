<?php

namespace Tests\Feature\Settings;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

/**
 * Deleting an account soft-deletes it: the user can't sign in again, but
 * their generations and ledger entries stay, so the admin report still
 * matches what Pixelforge billed.
 */
class AccountDeletionTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    public function test_a_user_with_money_history_can_delete_their_account(): void
    {
        $this->fakePixelforge();
        $user = $this->userWithCredit(5_000_000_000);
        $generation = $this->submitGeneration($user);

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertSoftDeleted($user);
        $this->assertDatabaseHas('generations', ['id' => $generation->id, 'user_id' => $user->id]);
        $this->assertSame(2, LedgerEntry::query()->where('user_id', $user->id)->count());   // purchase + hold
    }

    public function test_a_deleted_user_cannot_sign_in(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_a_deleted_users_other_sessions_end(): void
    {
        $sessionKey = 'login_web_'.sha1(SessionGuard::class);
        $active = User::factory()->create();
        $user = User::factory()->create();
        $user->delete();

        // Control: the same session shape signs in a user who isn't deleted.
        $this->withSession([$sessionKey => $active->id])->get(route('generations.index'))->assertOk();
        $this->app['auth']->forgetGuards();

        // A session still holding the deleted user's id: the guard
        // reloads the user by id, which skips deleted rows.
        $this->withSession([$sessionKey => $user->id])
            ->get(route('generations.index'))
            ->assertRedirect(route('login'));
    }

    public function test_seeding_again_leaves_a_deleted_account_deleted(): void
    {
        config(['seed.admin' => ['email' => 'admin@example.com', 'password' => 'admin-secret']]);
        config(['seed.demo' => ['email' => 'demo@example.com', 'password' => 'demo-secret']]);
        $this->seed();
        User::query()->where('email', 'demo@example.com')->firstOrFail()->delete();

        $this->seed();

        $this->assertSame(2, User::withTrashed()->count());
        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseCount('ledger_entries', 2);   // no second $5
    }
}
