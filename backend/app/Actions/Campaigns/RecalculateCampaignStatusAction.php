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

        if ($campaign->status !== $newStatus) {
            $campaign->forceFill(['status' => $newStatus])->save();
        }

        return $newStatus;
    }
}
