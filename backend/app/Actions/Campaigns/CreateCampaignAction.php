<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\EmailJobStatus;
use App\Jobs\ProcessEmailJob;
use App\Models\Campaign;
use App\Models\EmailJob;
use Illuminate\Support\Facades\DB;

final class CreateCampaignAction
{
    /**
     * Create a campaign, persist recipient records, and dispatch queued sending jobs.
     *
     * @param  array{name: string, subject: string, body: string, recipient_emails: array<int, string>}  $data
     */
    public function execute(array $data): Campaign
    {
        return DB::transaction(function () use ($data) {
            $campaign = Campaign::create([
                'name' => $data['name'],
                'subject' => $data['subject'],
                'body' => $data['body'],
                'recipient_count' => count($data['recipient_emails']),
            ]);
            $campaign->forceFill(['status' => CampaignStatus::Queued])->save();

            foreach ($data['recipient_emails'] as $email) {
                $emailJob = EmailJob::create([
                    'campaign_id' => $campaign->id,
                    'recipient_email' => $email,
                    'status' => EmailJobStatus::Pending,
                ]);

                // Dispatch using after-commit behaviour so jobs only hit the queue once committed
                ProcessEmailJob::dispatch($emailJob->id)->afterCommit();
            }

            return $campaign;
        });
    }
}
