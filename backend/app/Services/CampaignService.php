<?php

namespace App\Services;

use App\Actions\Campaigns\CreateCampaignAction;
use App\Models\Campaign;

class CampaignService
{
    public function __construct(private readonly CreateCampaignAction $createCampaignAction) {}

    /**
     * Create a campaign, persist recipient records, and dispatch queued sending jobs.
     *
     * @param  array{name: string, subject: string, body: string, recipient_emails: array<int, string>}  $data
     */
    public function createCampaign(array $data): Campaign
    {
        return $this->createCampaignAction->execute($data);
    }
}
