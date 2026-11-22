<?php

namespace App\Enums;

/**
 * Ledger entry types. A user's balance is the sum of their entries.
 *
 * | Event                         | Entry                               |
 * | ----------------------------- | ----------------------------------- |
 * | Buy credits                   | purchase +amount                    |
 * | Submit                        | hold −maximum                       |
 * | Success                       | release +maximum, charge −actual    |
 * | Failure, rejection, giving up | release +maximum                    |
 */
enum LedgerType: string
{
    case Purchase = 'purchase';
    case Hold = 'hold';
    case Release = 'release';
    case Charge = 'charge';
}
