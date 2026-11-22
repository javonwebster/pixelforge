<?php

namespace App\Http\Controllers;

use App\Services\AdminReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin report, for a date range of up to 92 UTC days (Pixelforge's
 * /v1/usage limit). Defaults to the last 7 days.
 */
class AdminReportController extends Controller
{
    public const int DEFAULT_DAYS = 7;

    public function __invoke(Request $request, AdminReport $report): Response
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

        return Inertia::render('admin/Report', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'maxDays' => AdminReport::MAX_DAYS,
            'report' => $report->build($from, $to),
        ]);
    }
}
