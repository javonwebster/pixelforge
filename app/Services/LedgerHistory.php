<?php

namespace App\Services;

use App\Enums\LedgerType;
use App\Models\User;
use App\Support\Nanodollars;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A user's ledger as a readable statement: newest first, each entry with a
 * plain label, the generation it belongs to, and the balance after it.
 *
 * The running balance is a window sum over the user's whole ledger,
 * computed before any filter, so a filtered or paginated page still shows
 * the true balance after each entry.
 *
 * @phpstan-type Money array{nanos: int, display: string, exact: string}
 * @phpstan-type Entry array{id: int, type: string, label: string, generationId: string|null, prompt: string|null, amount: Money, balanceAfter: Money, createdAt: string}
 */
class LedgerHistory
{
    public const int PER_PAGE = 25;

    /**
     * Amounts and balances show 4 decimals here, not whole cents like the
     * rest of the app: this page exists to show the arithmetic, and charges
     * are fractions of a cent ($0.0738), so whole cents wouldn't add up.
     * The exact 9-decimal figure stays on hover.
     */
    public const int DISPLAY_DECIMALS = 4;

    /**
     * Dates are days in their own time zone (the viewer's), both inclusive,
     * turned into UTC bounds here; min and max compare the absolute amount,
     * in nanodollars.
     *
     * @param  array{from?: CarbonImmutable|null, to?: CarbonImmutable|null, min?: int|null, max?: int|null}  $filters
     * @return LengthAwarePaginator<int|string, Entry>
     */
    public function forUser(User $user, array $filters): LengthAwarePaginator
    {
        $withBalance = DB::table('ledger_entries')
            ->where('user_id', $user->id)
            ->select('ledger_entries.*')
            ->selectRaw('SUM(amount_nanos) OVER (ORDER BY id) AS balance_after_nanos');

        $query = DB::query()
            ->fromSub($withBalance, 'entries')
            ->leftJoin('generations', 'generations.id', '=', 'entries.generation_id')
            ->select('entries.id', 'entries.type', 'entries.amount_nanos', 'entries.balance_after_nanos', 'entries.created_at', 'entries.generation_id', 'generations.prompt')
            ->orderByDesc('entries.id');

        if (($from = $filters['from'] ?? null) !== null) {
            // created_at is stored in UTC, and a bound is written out in its
            // own zone, so convert before comparing.
            $query->where('entries.created_at', '>=', $from->startOfDay()->utc());
        }
        if (($to = $filters['to'] ?? null) !== null) {
            $query->where('entries.created_at', '<', $to->addDay()->startOfDay()->utc());
        }
        if (($min = $filters['min'] ?? null) !== null) {
            $query->whereRaw('ABS(entries.amount_nanos) >= ?', [$min]);
        }
        if (($max = $filters['max'] ?? null) !== null) {
            $query->whereRaw('ABS(entries.amount_nanos) <= ?', [$max]);
        }

        return $query->paginate(self::PER_PAGE)->withQueryString()->through(function (object $row): array {
            /** @var object{id: int, type: string, amount_nanos: int|string, balance_after_nanos: int|string, created_at: string, generation_id: string|null, prompt: string|null} $row */
            $type = LedgerType::from($row->type);
            $amount = (int) $row->amount_nanos;

            return [
                'id' => (int) $row->id,
                'type' => $type->value,
                'label' => self::label($type),
                'generationId' => $row->generation_id,
                'prompt' => $row->prompt === null ? null : Str::limit($row->prompt, 70),
                'amount' => [
                    ...Nanodollars::present($amount),
                    // "+$5.0000" / "-$0.0738": the sign is the point of a ledger.
                    'display' => ($amount > 0 ? '+' : '').Nanodollars::format($amount, self::DISPLAY_DECIMALS),
                ],
                'balanceAfter' => [
                    // SUM(bigint) comes back from Postgres as numeric, a string.
                    ...Nanodollars::present($balance = (int) $row->balance_after_nanos),
                    'display' => Nanodollars::format($balance, self::DISPLAY_DECIMALS),
                ],
                'createdAt' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
            ];
        });
    }

    public static function label(LedgerType $type): string
    {
        return match ($type) {
            LedgerType::Purchase => 'Credits purchased',
            LedgerType::Hold => 'Held for a generation',
            LedgerType::Release => 'Hold returned',
            LedgerType::Charge => 'Charged for a generation',
        };
    }
}
