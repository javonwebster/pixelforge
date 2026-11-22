<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Requests\QuoteRequest;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\Pricing\PricingCalculator;
use App\Services\Pricing\PricingCatalog;
use App\Support\Nanodollars;
use Illuminate\Http\JsonResponse;

/**
 * The live price panel: estimate and maximum, both with the markup, from
 * the same PricingCalculator that submits use, so the number shown is the
 * number held.
 */
class QuoteController extends Controller
{
    public function __invoke(QuoteRequest $request, PricingCalculator $calculator, PricingCatalog $catalog, LedgerService $ledger): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $quote = $calculator->quote(
            (string) $request->input('prompt', ''),
            $request->size(),
            $request->quality(),
            count($request->referenceImages()),
            $catalog->currentRates(),
        );
        $available = $ledger->available($user);
        $enough = $available >= $quote->maximumPrice;

        return response()->json([
            'estimate' => Nanodollars::present($quote->estimatedPrice),
            'maximum' => Nanodollars::present($quote->maximumPrice),
            'available' => Nanodollars::present($available),
            'enough' => $enough,
            // The same words the submit would refuse with, made in one place.
            'shortfallMessage' => $enough ? null : (new InsufficientBalanceException($quote->maximumPrice, $available))->getMessage(),
        ]);
    }
}
