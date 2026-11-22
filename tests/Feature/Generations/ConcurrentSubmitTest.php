<?php

namespace Tests\Feature\Generations;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Generation;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

/**
 * Real concurrency needs committed rows and separate connections, so these
 * tests truncate instead of wrapping each test in a transaction.
 */
class ConcurrentSubmitTest extends TestCase
{
    use DatabaseTruncation, FakesPixelforge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePixelforge();
        config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
    }

    protected function tearDown(): void
    {
        // DatabaseTruncation cleans up before each test, not after. These
        // rows are committed, so clear them before the next test class,
        // which expects an empty database inside its own transaction.
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_submit_waits_for_the_users_row_lock(): void
    {
        $user = $this->userWithCredit(5_000_000_000);

        // Another session holds the user's row lock, as a concurrent
        // submit would while it checks the balance and writes its hold.
        $other = DB::connection('pgsql_second');
        $other->beginTransaction();
        $other->select('select id from users where id = ? for update', [$user->id]);

        DB::statement("set lock_timeout = '300ms'");

        try {
            $this->submitGeneration($user);
            $this->fail('Submit should have waited for the lock.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode());   // lock_not_available
        } finally {
            DB::statement('reset lock_timeout');
            $other->rollBack();
        }

        $this->assertSame(0, Generation::query()->count());
    }

    public function test_two_submits_at_once_with_credit_for_one(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Needs pcntl to run two submits in parallel.');
        }

        // $0.15: covers one $0.0866 maximum, not two.
        $user = $this->userWithCredit(150_000_000);
        $results = [];
        $start = microtime(true) + 0.5;

        // Children open their own connections. Close ours first so a child
        // can't tear down a socket the parent still uses.
        DB::disconnect();

        $pids = [];
        foreach ([0, 1] as $i) {
            $file = tempnam(sys_get_temp_dir(), 'pf-submit-');
            $pid = pcntl_fork();

            if ($pid === 0) {
                // A stuck child dies after 20 s instead of hanging the suite.
                pcntl_alarm(20);
                DB::purge();
                time_sleep_until($start);

                try {
                    $this->submitGeneration(User::query()->findOrFail($user->id));
                    $outcome = 'accepted';
                } catch (InsufficientBalanceException) {
                    $outcome = 'refused';
                } catch (\Throwable $e) {
                    $outcome = 'error: '.$e->getMessage();
                }

                file_put_contents($file, $outcome);
                // Skip PHPUnit's shutdown in the child.
                posix_kill(posix_getpid(), SIGKILL);
            }

            $pids[$pid] = $file;
        }

        $deadline = microtime(true) + 30;
        foreach ($pids as $pid => $file) {
            while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                if (microtime(true) > $deadline) {
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);
                    break;
                }
                usleep(50_000);
            }
            $results[] = (string) file_get_contents($file);
            unlink($file);
        }

        sort($results);
        $this->assertSame(['accepted', 'refused'], $results);
        $this->assertSame(1, Generation::query()->count());
        $this->assertSame(150_000_000 - self::MAX_PRICE, app(LedgerService::class)->available($user));
    }
}
