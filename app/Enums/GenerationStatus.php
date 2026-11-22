<?php

namespace App\Enums;

/**
 * A generation's lifecycle, with the rank that decides which updates win.
 *
 * Webhooks arrive at least once and out of order, so a status is applied
 * only if its rank is higher than the current one, and nothing changes a
 * terminal status. `submission_failed` sits at rank 0 on purpose: the
 * outcome was unclear, so if Pixelforge later reports the generation it
 * moves forward and is billed like any other.
 */
enum GenerationStatus: string
{
    /** Created locally, not yet accepted by Pixelforge. */
    case Pending = 'pending';

    /** The submit job gave up with an unclear outcome. Pixelforge may have it. */
    case SubmissionFailed = 'submission_failed';

    case Queued = 'queued';
    case Processing = 'processing';

    /** Billed. */
    case Succeeded = 'succeeded';

    /** Not billed. */
    case Failed = 'failed';

    /** Pixelforge definitely created nothing. Not billed. */
    case Rejected = 'rejected';

    public function rank(): int
    {
        return match ($this) {
            self::Pending, self::SubmissionFailed => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed, self::Rejected => 3,
        };
    }

    public function isTerminal(): bool
    {
        return $this->rank() === 3;
    }

    /** Whether a status reported by Pixelforge may replace this one. */
    public function canAdvanceTo(self $incoming): bool
    {
        return ! $this->isTerminal() && $incoming->rank() > $this->rank();
    }

    /**
     * Statuses Pixelforge itself reports. The rest are ours.
     *
     * @return list<self>
     */
    public static function fromPixelforge(): array
    {
        return [self::Queued, self::Processing, self::Succeeded, self::Failed];
    }

    /**
     * Not finished: History keeps polling while any generation is in one.
     *
     * @return list<self>
     */
    public static function unfinished(): array
    {
        return [self::Pending, self::Queued, self::Processing];
    }
}
