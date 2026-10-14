<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\EmailJobStatus;
use App\Models\Campaign;

final class RecalculateCampaignStatusAction
{
    /**
     * Re-calculate and persist campaign status based on current recipient statuses.
     */
    public function execute(Campaign $campaign): CampaignStatus
    {
        $hasActiveWork = $campaign->emailJobs()
            ->whereIn('status', [EmailJobStatus::Pending->value, EmailJobStatus::Processing->value])
            ->exists();

        if (! $hasActiveWork) {
            $newStatus = CampaignStatus::Done;
        } else {
            $hasStarted = $campaign->emailJobs()
                ->whereIn('status', [
                    EmailJobStatus::Processing->value,
                    EmailJobStatus::Sent->value,
                    EmailJobStatus::Failed->value,
                ])
                ->exists();

            $newStatus = $hasStarted ? CampaignStatus::Processing : CampaignStatus::Queued;
        }

        // Conditional forward-only update: a worker holding a stale view can never
        // overwrite a status computed by a worker that finished after it
        Campaign::query()
            ->whereKey($campaign->getKey())
            ->whereIn('status', $newStatus->precedingValues())
            ->update([
                'status' => $newStatus->value,
                'updated_at' => now(),
            ]);

        $campaign->refresh();

        return $campaign->status;
    }
}
