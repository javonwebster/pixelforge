<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\LedgerHistory;
use App\Services\LedgerService;
use App\Support\Nanodollars;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Credits: the transaction history, and buying more. Buying is fake (no
 * payment is taken); it adds a purchase entry to the ledger. Credits are
 * US dollars.
 */
class CreditController extends Controller
{
    /** Quick picks in the Buy credits dialog. Any amount in range can be typed. */
    public const array PRESETS = ['5', '10', '20', '50'];

    public const int MIN_DOLLARS = 1;

    public const int MAX_DOLLARS = 500;

    /** Whole dollars or dollars and cents: "12" or "12.50". Never a float. */
    private const string AMOUNT_PATTERN = '/^\d{1,4}(\.\d{1,2})?$/';

    public function index(Request $request, LedgerService $ledger, LedgerHistory $history): Response
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'min' => ['nullable', 'string', 'regex:'.self::AMOUNT_PATTERN],
            'max' => ['nullable', 'string', 'regex:'.self::AMOUNT_PATTERN],
            // The viewer's time zone, sent by the page. The table shows local
            // times, so "22 Nov" means the viewer's 22 Nov, not UTC's.
            'tz' => ['nullable', 'string', 'timezone:all'],
        ]);

        $tz = $validated['tz'] ?? 'UTC';
        $filters = [
            'from' => isset($validated['from']) ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['from'], $tz) : null,
            'to' => isset($validated['to']) ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['to'], $tz) : null,
            'min' => isset($validated['min']) ? Nanodollars::fromUsd($validated['min']) : null,
            'max' => isset($validated['max']) ? Nanodollars::fromUsd($validated['max']) : null,
        ];

        return Inertia::render('credits/Index', [
            'balance' => fn () => GenerationController::balance($user, $ledger),
            'entries' => $history->forUser($user, $filters),
            'filters' => [
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
                'min' => $validated['min'] ?? null,
                'max' => $validated['max'] ?? null,
            ],
        ]);
    }

    public function store(Request $request, LedgerService $ledger): RedirectResponse
    {
        // Accept 10 as well as "10"; validate the text, never a float.
        $request->merge(['amount' => is_scalar($request->input('amount')) ? (string) $request->input('amount') : null]);

        $validated = $request->validate([
            'amount' => ['required', 'string', 'regex:'.self::AMOUNT_PATTERN],
        ], [
            'amount.regex' => 'Enter an amount in dollars, like 25 or 12.50.',
        ]);

        $nanos = Nanodollars::fromUsd($validated['amount']);

        if ($nanos < self::MIN_DOLLARS * Nanodollars::PER_USD || $nanos > self::MAX_DOLLARS * Nanodollars::PER_USD) {
            throw ValidationException::withMessages([
                'amount' => 'Choose between $'.self::MIN_DOLLARS.' and $'.self::MAX_DOLLARS.'.',
            ]);
        }

        /** @var User $user */
        $user = $request->user();
        $ledger->purchase($user, $nanos);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Added '.Nanodollars::format($nanos).' of credit.']);

        return back();
    }
}
