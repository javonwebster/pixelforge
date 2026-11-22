<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\LedgerService;
use App\Support\Nanodollars;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Buy credits. Fake: no payment is taken, it just adds a purchase entry.
 * The buttons disable while a request is in flight, so a double click
 * can't add twice.
 */
class CreditController extends Controller
{
    /** Dollar amounts on offer. */
    public const array AMOUNTS = [5, 10, 20];

    public function store(Request $request, LedgerService $ledger): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', Rule::in(self::AMOUNTS)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $nanos = (int) $validated['amount'] * Nanodollars::PER_USD;
        $ledger->purchase($user, $nanos);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Added '.Nanodollars::format($nanos).' of credit.']);

        return back();
    }
}
