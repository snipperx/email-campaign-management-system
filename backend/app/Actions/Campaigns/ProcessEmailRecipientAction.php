<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Enums\EmailJobStatus;
use App\Models\EmailJob;
use App\Services\EmailSenderService;

final class ProcessEmailRecipientAction
{
    public function __construct(
        private readonly EmailSenderService $sender,
        private readonly RecalculateCampaignStatusAction $recalculateAction,
    ) {}

    /**
     * Send simulated email for recipient and update records.
     */
    public function execute(EmailJob $emailJob): void
    {
        $this->sender->send($emailJob);

        $emailJob->update([
            'status' => EmailJobStatus::Sent,
            'sent_at' => now(),
            'error_message' => null,
        ]);

        if ($emailJob->campaign) {
            $this->recalculateAction->execute($emailJob->campaign);
        }
    }
}
