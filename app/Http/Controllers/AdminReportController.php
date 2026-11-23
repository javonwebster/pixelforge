<?php

namespace App\Http\Controllers;

use App\Services\AdminReport;
use App\Support\Nanodollars;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The admin report, for a date range of up to 92 UTC days (Pixelforge's
 * /v1/usage limit). Defaults to the last 7 days. Also downloadable as CSV.
 */
class AdminReportController extends Controller
{
    public const int DEFAULT_DAYS = 7;

    public const array CSV_COLUMNS = [
        'row_type', 'date', 'user_id', 'user_name', 'user_email', 'generations',
        'credit_spend_usd', 'provider_cost_usd', 'profit_usd',
        'pixelforge_generations', 'pixelforge_cost_usd', 'matches',
    ];

    public function show(Request $request, AdminReport $report): Response
    {
        [$from, $to] = $this->range($request);

        return Inertia::render('admin/Report', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'maxDays' => AdminReport::MAX_DAYS,
            'report' => $report->build($from, $to),
        ]);
    }

    /**
     * The same report as rows for a spreadsheet. Amounts are exact USD
     * strings with nine decimals (no "$"), so they sum without rounding.
     * row_type says what each row is: a `day`, a `user` within that day, an
     * `account` total for the range, or the range `total`.
     */
    public function export(Request $request, AdminReport $report): StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $data = $report->build($from, $to);

        return response()->streamDownload(function () use ($data): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fputcsv($out, self::CSV_COLUMNS, escape: '');

            foreach ($data['days'] as $day) {
                fputcsv($out, self::row('day', $day['date'], null, $day, $day['pixelforge'], $day['matches']), escape: '');

                foreach ($day['users'] as $user) {
                    fputcsv($out, self::row('user', $day['date'], $user, $user), escape: '');
                }
            }

            foreach ($data['accounts'] as $account) {
                fputcsv($out, self::row('account', '', $account, $account), escape: '');
            }

            $totals = $data['totals'];
            fputcsv($out, self::row('total', '', null, $totals, $totals['pixelforge'], $totals['matches']), escape: '');

            fclose($out);
        }, "pixelforge-report-{$from->toDateString()}-to-{$to->toDateString()}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $user
     * @param  array<string, mixed>  $figures
     * @param  array<string, mixed>|null  $pixelforge
     * @return list<string>
     */
    private static function row(string $type, string $date, ?array $user, array $figures, ?array $pixelforge = null, ?bool $matches = null): array
    {
        return [
            $type,
            $date,
            (string) ($user['id'] ?? ''),
            self::safeText((string) ($user['name'] ?? '')),
            self::safeText((string) ($user['email'] ?? '')),
            (string) $figures['generations'],
            Nanodollars::toUsd((int) $figures['spendNanos']),
            Nanodollars::toUsd((int) $figures['costNanos']),
            Nanodollars::toUsd((int) $figures['profitNanos']),
            $pixelforge === null ? '' : (string) $pixelforge['generations'],
            $pixelforge === null ? '' : Nanodollars::toUsd((int) $pixelforge['costNanos']),
            $matches === null ? '' : ($matches ? 'true' : 'false'),
        ];
    }

    /**
     * Names and emails are user-supplied. A cell starting with =, +, - or @
     * would run as a formula when the file is opened in a spreadsheet.
     */
    private static function safeText(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(Request $request): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
        ]);

        $to = isset($validated['to']) ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['to'], 'UTC') : $today;
        $from = isset($validated['from'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['from'], 'UTC')
            : $to->subDays(self::DEFAULT_DAYS - 1);

        if ($from === null || $to === null || $from->greaterThan($to)) {
            throw ValidationException::withMessages(['from' => 'The start date must be on or before the end date.']);
        }

        // Both ends count: 1 Nov to 1 Nov is one day.
        if ($from->diffInDays($to) + 1 > AdminReport::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'Pick at most '.AdminReport::MAX_DAYS.' days (Pixelforge\'s usage limit).']);
        }

        return [$from, $to];
    }
}
