<?php

namespace App\Services;

use App\Models\EmailJob;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class EmailSenderService
{
    /**
     * Simulate sending an email for the given email job record.
     *
     * @throws RuntimeException
     */
    public function send(EmailJob $emailJob): void
    {
        Log::info('Simulating email dispatch', [
            'email_job_id' => $emailJob->id,
            'campaign_id' => $emailJob->campaign_id,
            'recipient' => $emailJob->recipient_email,
        ]);

        // If the email address explicitly designates a simulated failure (useful for tests and demo)
        if (str_contains(strtolower($emailJob->recipient_email), 'fail')) {
            throw new RuntimeException('Simulated provider delivery failure for '.$emailJob->recipient_email);
        }

        // Successful simulated send
    }
}
