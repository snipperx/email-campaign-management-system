<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Enums\EmailJobStatus;
use App\Jobs\ProcessEmailJob;
use App\Models\EmailJob;
use Illuminate\Support\Collection;

final class RecoverStalledEmailJobsAction
{
    /**
     * Comfortably longer than a job's full lifetime (3 tries x 30s timeout + 40s backoff).
     */
    public const STALE_AFTER_MINUTES = 15;

    /**
     * Re-dispatch recipients whose queued job was lost (never dispatched, or worker died).
     *
     * Safe to run repeatedly: the atomic claim in ProcessEmailJob ensures a recipient that
     * ends up with more than one queued job is still only sent once.
     *
     * @return int Number of recipients re-dispatched
     */
    public function execute(): int
    {
        $cutoff = now()->subMinutes(self::STALE_AFTER_MINUTES);
        $recovered = 0;

        EmailJob::query()
            ->whereIn('status', [EmailJobStatus::Pending->value, EmailJobStatus::Processing->value])
            ->where('updated_at', '<', $cutoff)
            ->chunkById(500, function (Collection $emailJobs) use ($cutoff, &$recovered) {
                foreach ($emailJobs as $emailJob) {
                    // Conditional reset: skip records a worker has touched since they were selected.
                    // Touching updated_at also stops the next sweep re-dispatching it straight away.
                    $reset = EmailJob::query()
                        ->whereKey($emailJob->id)
                        ->where('status', $emailJob->status->value)
                        ->where('updated_at', '<', $cutoff)
                        ->update([
                            'status' => EmailJobStatus::Pending->value,
                            'claim_token' => null,
                            'updated_at' => now(),
                        ]);

                    if ($reset === 1) {
                        ProcessEmailJob::dispatch($emailJob->id);
                        $recovered++;
                    }
                }
            });

        return $recovered;
    }
}
