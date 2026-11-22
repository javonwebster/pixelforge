<?php

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The spec's status table, row by row.
 */
class StatusLabelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{GenerationStatus, ?string, ?string, string, ?string}>
     */
    public static function statuses(): array
    {
        return [
            'pending, not yet tried' => [GenerationStatus::Pending, null, null, 'Waiting for a slot', null],
            'pending, waiting on concurrency' => [GenerationStatus::Pending, 'concurrency_limit_exceeded', null, 'Waiting for a slot', null],
            'pending after 503' => [GenerationStatus::Pending, 'overloaded', null, 'Pixelforge is busy, retrying', null],
            'pending after no response' => [GenerationStatus::Pending, 'connection_error', null, 'Pixelforge is busy, retrying', null],
            'queued' => [GenerationStatus::Queued, null, null, 'Queued', null],
            'processing' => [GenerationStatus::Processing, null, null, 'Processing', null],
            'succeeded' => [GenerationStatus::Succeeded, null, null, 'Succeeded', null],
            'failed: generation_failed' => [GenerationStatus::Failed, null, 'generation_failed', 'Failed', "Pixelforge couldn't make an image for this prompt"],
            'failed: internal_error, no retry made' => [GenerationStatus::Failed, null, 'internal_error', 'Failed', 'Pixelforge had an error'],
            'rejected: content policy' => [GenerationStatus::Rejected, null, 'content_policy_violation', 'Failed', 'Blocked by content policy'],
            'rejected: daily limit' => [GenerationStatus::Rejected, null, 'daily_limit_exceeded', 'Failed', 'Daily limit reached'],
            'rejected: our bug' => [GenerationStatus::Rejected, null, 'validation_failed', 'Failed', 'Something went wrong'],
            'rejected: gave up on overloads' => [GenerationStatus::Rejected, null, 'overloaded', 'Failed', 'Pixelforge was too busy. Try again in a few minutes.'],
            'rejected: gave up waiting for a slot' => [GenerationStatus::Rejected, null, 'concurrency_limit_exceeded', 'Failed', 'Pixelforge was too busy. Try again in a few minutes.'],
            'submission_failed' => [GenerationStatus::SubmissionFailed, null, 'connection_error', 'Failed', "Couldn't reach Pixelforge"],
        ];
    }

    #[DataProvider('statuses')]
    public function test_status_label(GenerationStatus $status, ?string $lastSubmitError, ?string $errorCode, string $label, ?string $detail): void
    {
        $generation = Generation::factory()->status($status)->create([
            'last_submit_error' => $lastSubmitError,
            'error_code' => $errorCode,
        ]);

        $this->assertSame($label, GenerationResource::label($generation));
        $this->assertSame($detail, GenerationResource::detail($generation));
    }

    public function test_every_failure_shows_charged_zero_and_try_again(): void
    {
        foreach ([GenerationStatus::Failed, GenerationStatus::Rejected, GenerationStatus::SubmissionFailed] as $status) {
            $generation = Generation::factory()->status($status)->create(['charge_nanos' => 0]);

            $row = (new GenerationResource($generation))->toArray(request());

            $this->assertTrue($row['failed'], $status->value);
            $this->assertSame('$0.00', $row['actualPrice']['display'], $status->value);
        }
    }
}
