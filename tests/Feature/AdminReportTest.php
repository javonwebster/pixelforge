<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.pixelforge.key' => 'pf_test_key']);
        $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
        $this->travelTo('2026-11-22 15:00:00');
    }

    /**
     * Pixelforge's /v1/usage: every day in the range, zeros included.
     *
     * @param  array<string, array{int, string}>  $days  date => [generations, cost_usd]
     */
    private function fakeUsage(array $days): void
    {
        Http::fake(['*/v1/usage*' => function (Request $request) use ($days) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $data = [];

            for ($d = strtotime((string) $query['start_date']); $d <= strtotime((string) $query['end_date']); $d += 86400) {
                $date = gmdate('Y-m-d', $d);
                [$count, $cost] = $days[$date] ?? [0, '0.000000000'];
                $data[] = ['date' => $date, 'generations' => $count, 'cost_usd' => $cost];
            }

            return Http::response(['object' => 'usage', 'start_date' => $query['start_date'], 'end_date' => $query['end_date'], 'data' => $data, 'total' => []]);
        }]);
    }

    private function billed(User $user, string $completedAt, int $cost, int $charge): Generation
    {
        return Generation::factory()->for($user)->status(GenerationStatus::Succeeded)->create([
            'completed_at' => $completedAt,
            'provider_cost_nanos' => $cost,
            'charge_nanos' => $charge,
        ]);
    }

    public function test_only_admins_can_see_it(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.report'))->assertForbidden();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('admin.report'))->assertRedirect(route('login'));
    }

    public function test_rows_per_utc_day_and_user_exact_to_the_nanodollar(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $this->billed($alice, '2026-11-21 10:00:00', 59_060_000, 73_825_000);
        $this->billed($alice, '2026-11-21 11:00:00', 59_787_500, 74_734_375);
        $this->billed($bob, '2026-11-21 12:00:00', 47_152_500, 58_940_625);
        $this->billed($bob, '2026-11-22 09:00:00', 60_937_500, 76_171_875);
        $this->fakeUsage([
            '2026-11-21' => [3, '0.166000000'],
            '2026-11-22' => [1, '0.060937500'],
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.report'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Report')
                ->where('from', '2026-11-16')
                ->where('to', '2026-11-22')
                ->has('report.days', 2)
                ->where('report.days.0.date', '2026-11-22')
                ->where('report.days.1.date', '2026-11-21')
                ->where('report.days.1.generations', 3)
                ->where('report.days.1.spend', '$0.207500000')
                ->where('report.days.1.cost', '$0.166000000')
                ->where('report.days.1.profit', '$0.041500000')
                ->where('report.days.1.users.0.name', 'Alice')
                ->where('report.days.1.users.0.generations', 2)
                ->where('report.days.1.users.0.spend', '$0.148559375')
                ->where('report.days.1.users.1.name', 'Bob')
                ->where('report.days.1.pixelforge.cost', '$0.166000000')
                ->where('report.days.1.matches', true)
                ->where('report.totals.generations', 4)
                ->where('report.totals.cost', '$0.226937500')
                ->where('report.totals.matches', true)
                ->where('report.pixelforgeError', null));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v1/usage?start_date=2026-11-16&end_date=2026-11-22')
            && $request->header('Authorization') === ['Bearer pf_test_key']);
    }

    public function test_totals_per_account_for_the_chart_biggest_spender_first(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $this->billed($alice, '2026-11-20 10:00:00', 59_060_000, 73_825_000);
        $this->billed($bob, '2026-11-21 10:00:00', 59_787_500, 74_734_375);
        $this->billed($bob, '2026-11-22 10:00:00', 47_152_500, 58_940_625);
        $this->fakeUsage([]);

        $this->actingAs($this->admin)
            ->get(route('admin.report'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('report.accounts', 2)
                ->where('report.accounts.0.name', 'Bob')
                ->where('report.accounts.0.generations', 2)
                ->where('report.accounts.0.spend', '$0.133675000')
                ->where('report.accounts.0.cost', '$0.106940000')
                ->where('report.accounts.0.profit', '$0.026735000')
                ->where('report.accounts.0.spendNanos', 133_675_000)
                ->where('report.accounts.0.costNanos', 106_940_000)
                ->where('report.accounts.0.profitNanos', 26_735_000)
                ->where('report.accounts.1.name', 'Alice'));
    }

    public function test_a_cost_belongs_to_the_day_pixelforge_completed_it(): void
    {
        // Submitted just before midnight UTC, finished just after.
        Generation::factory()->for($this->admin)->status(GenerationStatus::Succeeded)->create([
            'created_at' => '2026-11-20 23:59:30',
            'completed_at' => '2026-11-21 00:00:20',
            'provider_cost_nanos' => 59_060_000,
            'charge_nanos' => 73_825_000,
        ]);
        $this->fakeUsage(['2026-11-21' => [1, '0.059060000']]);

        $this->actingAs($this->admin)
            ->get(route('admin.report'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('report.days', 1)
                ->where('report.days.0.date', '2026-11-21')
                ->where('report.days.0.matches', true));
    }

    public function test_failed_and_unfinished_generations_are_not_billed(): void
    {
        Generation::factory()->for($this->admin)->status(GenerationStatus::Failed)->create(['completed_at' => '2026-11-21 10:00:00', 'provider_cost_nanos' => 0, 'charge_nanos' => 0]);
        Generation::factory()->for($this->admin)->status(GenerationStatus::Processing)->create();
        $this->fakeUsage([]);

        $this->actingAs($this->admin)
            ->get(route('admin.report'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('report.days', 0)
                ->where('report.totals.generations', 0)
                ->where('report.totals.matches', true));
    }

    public function test_a_mismatch_is_flagged(): void
    {
        $this->billed($this->admin, '2026-11-21 10:00:00', 59_060_000, 73_825_000);
        // Pixelforge billed one more generation that we have no record of.
        $this->fakeUsage(['2026-11-21' => [2, '0.118120000']]);

        $this->actingAs($this->admin)
            ->get(route('admin.report'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.days.0.cost', '$0.059060000')
                ->where('report.days.0.pixelforge.cost', '$0.118120000')
                ->where('report.days.0.pixelforge.generations', 2)
                ->where('report.days.0.matches', false)
                ->where('report.totals.matches', false));
    }

    public function test_a_day_only_pixelforge_billed_still_shows(): void
    {
        $this->fakeUsage(['2026-11-20' => [1, '0.059060000']]);

        $this->actingAs($this->admin)
            ->get(route('admin.report'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('report.days', 1)
                ->where('report.days.0.date', '2026-11-20')
                ->where('report.days.0.generations', 0)
                ->where('report.days.0.matches', false));
    }

    public function test_the_report_still_shows_when_pixelforge_cant_be_reached(): void
    {
        $this->billed($this->admin, '2026-11-21 10:00:00', 59_060_000, 73_825_000);
        Http::fake(['*/v1/usage*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Busy.']], 503)]);

        $this->actingAs($this->admin)
            ->get(route('admin.report'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.days.0.cost', '$0.059060000')
                ->where('report.days.0.pixelforge', null)
                ->where('report.days.0.matches', null)
                ->where('report.totals.matches', null)
                ->where('report.pixelforgeError', "Couldn't load Pixelforge's usage: Busy."));
    }

    public function test_usage_is_cached_for_a_minute(): void
    {
        $this->fakeUsage([]);

        $this->actingAs($this->admin)->get(route('admin.report'));
        $this->actingAs($this->admin)->get(route('admin.report'));
        Http::assertSentCount(1);

        $this->travel(61)->seconds();
        $this->actingAs($this->admin)->get(route('admin.report'));
        Http::assertSentCount(2);
    }

    public function test_deleted_accounts_still_count(): void
    {
        $gone = User::factory()->create(['name' => 'Gone']);
        $this->billed($gone, '2026-11-21 10:00:00', 59_060_000, 73_825_000);
        $gone->delete();
        $this->fakeUsage(['2026-11-21' => [1, '0.059060000']]);

        $this->actingAs($this->admin)
            ->get(route('admin.report'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.days.0.users.0.name', 'Gone')
                ->where('report.days.0.matches', true));
    }

    public function test_range_is_inclusive_and_at_most_92_days(): void
    {
        $this->fakeUsage([]);

        $this->actingAs($this->admin)
            ->get(route('admin.report', ['from' => '2026-08-23', 'to' => '2026-11-22']))   // 92 days
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('admin.report', ['from' => '2026-08-22', 'to' => '2026-11-22']))   // 93 days
            ->assertSessionHasErrors('from');
    }

    public function test_bad_ranges_are_refused(): void
    {
        $this->fakeUsage([]);

        $this->actingAs($this->admin)->get(route('admin.report', ['from' => '2026-11-22', 'to' => '2026-11-20']))->assertSessionHasErrors('from');
        $this->actingAs($this->admin)->get(route('admin.report', ['from' => 'yesterday']))->assertSessionHasErrors('from');
        $this->actingAs($this->admin)->get(route('admin.report', ['to' => '2026-11-23']))->assertSessionHasErrors('to');
    }

    public function test_admins_get_the_nav_link_and_others_dont(): void
    {
        $this->fakeUsage([]);

        $this->actingAs($this->admin)->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.is_admin', true));
        $this->actingAs(User::factory()->create())->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.is_admin', false));
    }
}
