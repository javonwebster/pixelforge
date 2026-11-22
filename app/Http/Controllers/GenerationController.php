<?php

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Enums\Quality;
use App\Enums\Size;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Requests\GenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\Nanodollars;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Generate page, submitting, and History.
 */
class GenerationController extends Controller
{
    public const int PER_PAGE = 12;

    /**
     * History: the user's generations, newest first. The page polls with
     * a partial reload of `generations` and `balance` while anything is
     * unfinished, so both are closures and only built when asked for.
     */
    public function index(Request $request, LedgerService $ledger): Response
    {
        $user = self::user($request);

        return Inertia::render('generations/Index', [
            'generations' => fn () => GenerationResource::collection(
                $user->generations()
                    ->with(['retryOf:id,created_at', 'retry:id,retry_of_id'])
                    ->latest()
                    ->orderByDesc('id')
                    ->paginate(self::PER_PAGE)
            ),
            'balance' => fn () => self::balance($user, $ledger),
        ]);
    }

    /**
     * The Generate form. ?from=<id> prefills it from one of the user's own
     * generations, for "Try again".
     */
    public function create(Request $request, LedgerService $ledger): Response
    {
        $user = self::user($request);
        $from = null;

        if ($request->filled('from')) {
            $id = $request->string('from')->toString();
            // Someone else's generation, or not an id at all: 404 either
            // way. (Postgres would reject a malformed uuid with an error.)
            abort_unless(Str::isUuid($id), 404);
            $from = $user->generations()->findOrFail($id);
        }

        return Inertia::render('generations/Create', [
            'balance' => fn () => self::balance($user, $ledger),
            'sizes' => array_map(fn (Size $size) => $size->value, Size::cases()),
            'qualities' => array_map(fn (Quality $quality) => $quality->value, Quality::cases()),
            'maxPromptCharacters' => GenerationRequest::MAX_PROMPT_CHARACTERS,
            'maxReferenceImages' => GenerationRequest::MAX_REFERENCE_IMAGES,
            'prefill' => [
                'prompt' => $from->prompt ?? '',
                'size' => $from?->size->value ?? Size::Square->value,
                'quality' => $from?->quality->value ?? Quality::Medium->value,
                'reference_images' => $from->reference_images ?? [],
            ],
        ]);
    }

    /**
     * Places the hold and queues the submit; never waits on Pixelforge.
     */
    public function store(GenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        try {
            $createGeneration->handle(
                self::user($request),
                $request->prompt(),
                $request->size(),
                $request->quality(),
                $request->referenceImages(),
            );
        } catch (InsufficientBalanceException $e) {
            throw ValidationException::withMessages(['balance' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Submitted. It updates here as it goes.']);

        return to_route('generations.index');
    }

    /**
     * @return array{available: array{nanos: int, display: string, exact: string}, held: array{nanos: int, display: string, exact: string}}
     */
    public static function balance(User $user, LedgerService $ledger): array
    {
        return [
            'available' => Nanodollars::present($ledger->available($user)),
            'held' => Nanodollars::present($ledger->held($user)),
        ];
    }

    private static function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
