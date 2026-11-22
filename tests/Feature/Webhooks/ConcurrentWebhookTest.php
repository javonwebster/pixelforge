<?php

namespace Tests\Feature\Webhooks;

use App\Enums\GenerationStatus;
use App\Enums\LedgerType;
use App\Models\LedgerEntry;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

/**
 * Two deliveries processed at the same moment, in separate processes with
 * their own database connections, against committed rows.
 */
class ConcurrentWebhookTest extends TestCase
{
    use DatabaseTruncation, FakesPixelforge;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Needs pcntl to deliver two webhooks in parallel.');
        }

        $this->fakePixelforge();
    }

    protected function tearDown(): void
    {
        // DatabaseTruncation cleans up before each test, not after.
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_two_succeeded_events_at_once_charge_once(): void
    {
        $user = $this->userWithCredit(5_000_000_000);
        $generation = $this->submitGeneration($user);
        // Different event ids, so the row lock (not the event dedupe) is
        // what has to stop the second charge: a webhook and a resend, say.
        $events = [$this->webhookEvent($generation, 'succeeded'), $this->webhookEvent($generation, 'succeeded')];

        $statuses = $this->deliverAtOnce($events);

        sort($statuses);
        $this->assertSame(['200', '200'], $statuses);
        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()?->status);
        $this->assertSame(1, LedgerEntry::query()->where('generation_id', $generation->id)->where('type', LedgerType::Charge)->count());
        $this->assertSame(5_000_000_000 - self::SUCCEEDED_PRICE, app(LedgerService::class)->available($user));
    }

    public function test_the_same_event_delivered_twice_at_once_is_stored_once(): void
    {
        $user = $this->userWithCredit(5_000_000_000);
        $generation = $this->submitGeneration($user);
        $event = $this->webhookEvent($generation, 'succeeded');

        $statuses = $this->deliverAtOnce([$event, $event]);

        $this->assertSame(['200', '200'], $statuses);
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertSame(1, LedgerEntry::query()->where('generation_id', $generation->id)->where('type', LedgerType::Charge)->count());
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<string> HTTP status of each delivery
     */
    private function deliverAtOnce(array $events): array
    {
        $start = microtime(true) + 0.5;
        DB::disconnect();   // children open their own connections
        $children = [];

        foreach ($events as $event) {
            $file = (string) tempnam(sys_get_temp_dir(), 'pf-webhook-');
            $pid = pcntl_fork();

            if ($pid === 0) {
                pcntl_alarm(20);
                DB::purge();
                time_sleep_until($start);
                file_put_contents($file, (string) $this->postWebhook($event)->status());
                posix_kill(posix_getpid(), SIGKILL);
            }

            $children[$pid] = $file;
        }

        $statuses = [];
        $deadline = microtime(true) + 30;
        foreach ($children as $pid => $file) {
            while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                if (microtime(true) > $deadline) {
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);
                    break;
                }
                usleep(50_000);
            }
            $statuses[] = (string) file_get_contents($file);
            unlink($file);
        }

        return $statuses;
    }
}
