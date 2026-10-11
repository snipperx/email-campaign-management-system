<?php

namespace App\Jobs;

use App\Actions\Campaigns\ProcessEmailRecipientAction;
use App\Actions\Campaigns\RecalculateCampaignStatusAction;
use App\Enums\CampaignStatus;
use App\Enums\EmailJobStatus;
use App\Models\EmailJob;
use App\Services\EmailSenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 30;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30];
    }

    /**
     * Create a new job instance.
     */
    public function __construct(public int $emailJobId)
    {
        $this->afterCommit = true;
    }

    /**
     * Execute the job.
     */
    public function handle(EmailSenderService $sender, ?ProcessEmailRecipientAction $recipientAction = null): void
    {
        $recipientAction ??= new ProcessEmailRecipientAction($sender, app(RecalculateCampaignStatusAction::class));

        // Atomic claim: only claim if pending, or if this worker is retrying its own processing job
        $claimed = EmailJob::query()
            ->whereKey($this->emailJobId)
            ->where(function ($query) {
                $query->where('status', EmailJobStatus::Pending->value);
                if ($this->attempts() > 1) {
                    $query->orWhere('status', EmailJobStatus::Processing->value);
                }
            })
            ->update([
                'status' => EmailJobStatus::Processing->value,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            Log::info('Email job already claimed or not pending', ['email_job_id' => $this->emailJobId]);

            return;
        }

        $emailJob = EmailJob::with('campaign')->find($this->emailJobId);
        if (! $emailJob) {
            return;
        }

        // Set campaign status to processing if it is currently queued
        if ($emailJob->campaign && $emailJob->campaign->status === CampaignStatus::Queued) {
            $emailJob->campaign->forceFill(['status' => CampaignStatus::Processing])->save();
        }

        try {
            $recipientAction->execute($emailJob);
        } catch (Throwable $e) {
            Log::warning('Transient delivery failure during email job', [
                'email_job_id' => $this->emailJobId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            // Allow the exception to bubble up for Laravel's retry mechanism
            throw $e;
        }
    }

    /**
     * Handle a job failure after retries are exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        $emailJob = EmailJob::with('campaign')->find($this->emailJobId);
        if (! $emailJob) {
            return;
        }

        $sanitizedError = $exception ? mb_substr($exception->getMessage(), 0, 500) : 'Job failed due to an unknown error.';

        $emailJob->update([
            'status' => EmailJobStatus::Failed,
            'error_message' => $sanitizedError,
        ]);

        Log::error('Email job permanently failed', [
            'email_job_id' => $this->emailJobId,
            'error' => $sanitizedError,
        ]);

        // Recalculate campaign status after terminal failure
        if ($emailJob->campaign) {
            app(RecalculateCampaignStatusAction::class)->execute($emailJob->campaign);
        }
    }
}
