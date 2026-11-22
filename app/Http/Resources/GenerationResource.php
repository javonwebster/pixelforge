<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Enums\PixelforgeErrorCode;
use App\Models\Generation;
use App\Support\Nanodollars;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of History, with the status already turned into words. Kept in
 * PHP so the label rules are tested once and the Vue only displays them.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Generation $generation */
        $generation = $this->resource;
        $failed = in_array($generation->status, [GenerationStatus::Failed, GenerationStatus::Rejected, GenerationStatus::SubmissionFailed], true);

        return [
            'id' => $generation->id,
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'referenceImages' => $generation->reference_images,
            'status' => $generation->status->value,
            'finished' => ! in_array($generation->status, GenerationStatus::unfinished(), true),
            'failed' => $failed,
            'label' => self::label($generation),
            'detail' => self::detail($generation),
            'tone' => self::tone($generation),
            'estimatedPrice' => Nanodollars::present($generation->estimated_price_nanos),
            'maximumPrice' => Nanodollars::present($generation->max_price_nanos),
            // Null until it's settled; exactly $0 for anything that failed.
            'actualPrice' => $generation->charge_nanos === null ? null : Nanodollars::present($generation->charge_nanos),
            'image' => self::image($generation),
            'retryOf' => $generation->retryOf === null ? null : [
                'id' => $generation->retryOf->id,
                'createdAt' => $generation->retryOf->created_at->toIso8601String(),
            ],
            'retriedAs' => $generation->retry?->id,
            'createdAt' => $generation->created_at->toIso8601String(),
        ];
    }

    public static function label(Generation $generation): string
    {
        return match ($generation->status) {
            GenerationStatus::Pending => self::isBusy($generation) ? 'Pixelforge is busy, retrying' : 'Waiting for a slot',
            GenerationStatus::Queued => 'Queued',
            GenerationStatus::Processing => 'Processing',
            GenerationStatus::Succeeded => 'Succeeded',
            GenerationStatus::Failed,
            GenerationStatus::Rejected,
            GenerationStatus::SubmissionFailed => 'Failed',
        };
    }

    /** Why it failed, in plain words. Null for anything that didn't. */
    public static function detail(Generation $generation): ?string
    {
        $code = PixelforgeErrorCode::tryFrom((string) $generation->error_code);

        return match ($generation->status) {
            GenerationStatus::Failed => match ($code) {
                PixelforgeErrorCode::InternalError => $generation->retry !== null
                    ? 'Pixelforge had an error, retried automatically'
                    : 'Pixelforge had an error',
                default => "Pixelforge couldn't make an image for this prompt",
            },
            GenerationStatus::Rejected => match ($code) {
                PixelforgeErrorCode::ContentPolicyViolation => 'Blocked by content policy',
                PixelforgeErrorCode::DailyLimitExceeded => 'Daily limit reached',
                default => 'Something went wrong',
            },
            GenerationStatus::SubmissionFailed => "Couldn't reach Pixelforge",
            default => null,
        };
    }

    /**
     * @return 'waiting'|'busy'|'running'|'succeeded'|'failed'
     */
    public static function tone(Generation $generation): string
    {
        return match ($generation->status) {
            GenerationStatus::Pending => self::isBusy($generation) ? 'busy' : 'waiting',
            GenerationStatus::Queued, GenerationStatus::Processing => 'running',
            GenerationStatus::Succeeded => 'succeeded',
            default => 'failed',
        };
    }

    /**
     * The image to show: our stored copy if the image job has run, else
     * Pixelforge's URL while it's valid (24 hours), else a placeholder.
     *
     * @return array{url: string|null, expired: bool, downloadUrl: string|null}|null
     */
    private static function image(Generation $generation): ?array
    {
        if ($generation->status !== GenerationStatus::Succeeded) {
            return null;
        }

        $live = $generation->image_url !== null
            && ($generation->image_url_expires_at === null || $generation->image_url_expires_at->isFuture());

        return [
            'url' => $live ? $generation->image_url : null,
            'expired' => ! $live,
            'downloadUrl' => $live ? route('generations.download', $generation, absolute: false) : null,
        ];
    }

    /** Last attempt was a 503 or no response, rather than a wait for a slot. */
    private static function isBusy(Generation $generation): bool
    {
        return in_array($generation->last_submit_error, [
            PixelforgeErrorCode::Overloaded->value,
            PixelforgeErrorCode::ConnectionError->value,
            PixelforgeErrorCode::UnexpectedResponse->value,
        ], true);
    }
}
