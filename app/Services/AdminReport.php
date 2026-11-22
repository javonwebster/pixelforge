<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Services\Pixelforge\PixelforgeApiException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\ThrottledLocallyException;
use App\Support\Nanodollars;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Per UTC day and per user: generations billed, what users were charged,
 * what Pixelforge cost us, and the profit, set beside Pixelforge's own
 * GET /v1/usage for the same days.
 *
 * A generation belongs to the UTC day Pixelforge says it completed, which
 * is how /v1/usage bills, so a generation submitted at 23:59 and finished
 * at 00:01 counts on the second day. Only succeeded generations are
 * billed. All figures are exact nanodollars.
 *
 * @see https://pixelforge.promptedit.dev/docs
 */
class AdminReport
{
    /** Pixelforge's limit for one /v1/usage call. */
    public const int MAX_DAYS = 92;

    /** Usage is re-fetched at most once a minute per range. */
    public const int USAGE_CACHE_SECONDS = 60;

    public function __construct(private readonly PixelforgeClient $client) {}

    /**
     * @return array{
     *     days: list<array<string, mixed>>,
     *     accounts: list<array<string, mixed>>,
     *     totals: array<string, mixed>,
     *     pixelforgeError: string|null,
     * }
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ours = $this->ourDays($from, $to);
        [$theirs, $error] = $this->theirDays($from, $to);

        // Every day either side has activity on, newest first.
        $dates = array_unique([...array_keys($ours), ...array_keys(array_filter(
            $theirs,
            fn (array $day) => $day['generations'] > 0 || $day['cost'] > 0,
        ))]);
        rsort($dates);

        $days = [];
        $totals = ['generations' => 0, 'spend' => 0, 'cost' => 0, 'pixelforgeGenerations' => 0, 'pixelforgeCost' => 0];
        $accounts = [];

        foreach ($dates as $date) {
            $day = $ours[$date] ?? ['generations' => 0, 'spend' => 0, 'cost' => 0, 'users' => []];
            $pixelforge = $theirs[$date] ?? null;

            $days[] = [
                'date' => $date,
                ...self::figures($day['generations'], $day['spend'], $day['cost']),
                'users' => array_map(fn (array $user) => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    ...self::figures($user['generations'], $user['spend'], $user['cost']),
                ], $day['users']),
                'pixelforge' => $pixelforge === null ? null : [
                    'generations' => $pixelforge['generations'],
                    'cost' => Nanodollars::format($pixelforge['cost'], 9),
                ],
                // Money is what has to match; counts are shown for context.
                'matches' => $error === null ? $day['cost'] === ($pixelforge['cost'] ?? 0) : null,
            ];

            foreach ($day['users'] as $user) {
                $accounts[$user['id']] ??= ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'generations' => 0, 'spend' => 0, 'cost' => 0];
                $accounts[$user['id']]['generations'] += $user['generations'];
                $accounts[$user['id']]['spend'] += $user['spend'];
                $accounts[$user['id']]['cost'] += $user['cost'];
            }

            $totals['generations'] += $day['generations'];
            $totals['spend'] += $day['spend'];
            $totals['cost'] += $day['cost'];
            $totals['pixelforgeGenerations'] += $pixelforge['generations'] ?? 0;
            $totals['pixelforgeCost'] += $pixelforge['cost'] ?? 0;
        }

        // Biggest spenders first, for the chart.
        usort($accounts, fn (array $a, array $b) => $b['spend'] <=> $a['spend'] ?: $a['name'] <=> $b['name']);

        return [
            'days' => $days,
            'accounts' => array_map(fn (array $account) => [
                'id' => $account['id'],
                'name' => $account['name'],
                'email' => $account['email'],
                ...self::figures($account['generations'], $account['spend'], $account['cost']),
                // Raw nanodollars for bar lengths; text uses the strings.
                'spendNanos' => $account['spend'],
                'costNanos' => $account['cost'],
                'profitNanos' => $account['spend'] - $account['cost'],
            ], $accounts),
            'totals' => [
                ...self::figures($totals['generations'], $totals['spend'], $totals['cost']),
                'pixelforge' => $error === null ? [
                    'generations' => $totals['pixelforgeGenerations'],
                    'cost' => Nanodollars::format($totals['pixelforgeCost'], 9),
                ] : null,
                'matches' => $error === null ? $totals['cost'] === $totals['pixelforgeCost'] : null,
            ],
            'pixelforgeError' => $error,
        ];
    }

    /**
     * Succeeded generations grouped by Pixelforge's completion day (UTC)
     * and user. Includes deleted accounts: their money still counts.
     *
     * @return array<string, array{generations: int, spend: int, cost: int, users: list<array{id: int, name: string, email: string, generations: int, spend: int, cost: int}>}>
     */
    private function ourDays(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('generations')
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', GenerationStatus::Succeeded->value)
            ->where('generations.completed_at', '>=', $from->utc()->startOfDay())
            ->where('generations.completed_at', '<', $to->utc()->addDay()->startOfDay())
            ->selectRaw("(generations.completed_at AT TIME ZONE 'UTC')::date AS day")
            ->selectRaw('users.id AS user_id, users.name, users.email')
            ->selectRaw('COUNT(*) AS generations')
            ->selectRaw('COALESCE(SUM(generations.charge_nanos), 0) AS spend')
            ->selectRaw('COALESCE(SUM(generations.provider_cost_nanos), 0) AS cost')
            ->groupBy('day', 'users.id', 'users.name', 'users.email')
            ->orderBy('day')
            ->orderBy('users.name')
            ->get();

        $days = [];

        foreach ($rows as $row) {
            /** @var object{day: string, user_id: int, name: string, email: string, generations: int|string, spend: int|string, cost: int|string} $row */
            $day = (string) $row->day;
            $days[$day] ??= ['generations' => 0, 'spend' => 0, 'cost' => 0, 'users' => []];

            // Postgres returns SUM(bigint) as numeric, i.e. a string.
            $user = [
                'id' => (int) $row->user_id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'generations' => (int) $row->generations,
                'spend' => (int) $row->spend,
                'cost' => (int) $row->cost,
            ];

            $days[$day]['users'][] = $user;
            $days[$day]['generations'] += $user['generations'];
            $days[$day]['spend'] += $user['spend'];
            $days[$day]['cost'] += $user['cost'];
        }

        return $days;
    }

    /**
     * Pixelforge's per-day usage for the production key, cached briefly:
     * the call is authenticated and counts against the rate limit.
     *
     * @return array{0: array<string, array{generations: int, cost: int}>, 1: string|null}
     */
    private function theirDays(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $key = "pixelforge:usage:{$from->toDateString()}:{$to->toDateString()}";

        try {
            /** @var array<string, array{generations: int, cost: int}> $days */
            $days = Cache::remember($key, self::USAGE_CACHE_SECONDS, function () use ($from, $to): array {
                $days = [];

                foreach ($this->client->usage($from->toDateString(), $to->toDateString()) as $day) {
                    if (! is_string($day['date'] ?? null) || ! is_string($day['cost_usd'] ?? null)) {
                        throw new InvalidArgumentException('Unexpected /v1/usage day.');
                    }

                    $days[$day['date']] = [
                        'generations' => (int) ($day['generations'] ?? 0),
                        'cost' => Nanodollars::fromUsd($day['cost_usd']),
                    ];
                }

                return $days;
            });

            return [$days, null];
        } catch (ThrottledLocallyException) {
            return [[], 'Pixelforge request budget is used up for this minute. Try again shortly.'];
        } catch (PixelforgeApiException|ConnectionException|InvalidArgumentException $e) {
            return [[], "Couldn't load Pixelforge's usage: {$e->getMessage()}"];
        }
    }

    /**
     * @return array{generations: int, spend: string, cost: string, profit: string}
     */
    private static function figures(int $generations, int $spend, int $cost): array
    {
        return [
            'generations' => $generations,
            'spend' => Nanodollars::format($spend, 9),
            'cost' => Nanodollars::format($cost, 9),
            'profit' => Nanodollars::format($spend - $cost, 9),
        ];
    }
}
