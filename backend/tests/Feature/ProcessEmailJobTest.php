<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\EmailJobStatus;
use App\Jobs\ProcessEmailJob;
use App\Models\Campaign;
use App\Models\EmailJob;
use App\Services\EmailSenderService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessEmailJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_successfully_processes_email_job_and_updates_status(): void
    {
        $campaign = Campaign::create([
            'name' => 'Launch Campaign',
            'subject' => 'Launch Subject',
            'body' => 'Launch Body',
            'recipient_count' => 1,
        ]);
        $campaign->forceFill(['status' => CampaignStatus::Queued])->save();

        $emailJob = EmailJob::create([
            'campaign_id' => $campaign->id,
            'recipient_email' => 'customer@example.com',
            'status' => EmailJobStatus::Pending,
        ]);

        $sender = new EmailSenderService;
        $job = new ProcessEmailJob($emailJob->id);
        $job->handle($sender);

        $emailJob->refresh();
        $campaign->refresh();

        $this->assertEquals(EmailJobStatus::Sent, $emailJob->status);
        $this->assertNotNull($emailJob->sent_at);
        $this->assertNull($emailJob->error_message);
        $this->assertEquals(CampaignStatus::Done, $campaign->status);
    }

    public function test_atomic_claim_prevents_competing_worker_from_processing(): void
    {
        $campaign = Campaign::create([
            'name' => 'Concurrent Test',
            'subject' => 'Subject',
            'body' => 'Body',
            'recipient_count' => 1,
        ]);
        $campaign->forceFill(['status' => CampaignStatus::Queued])->save();

        $emailJob = EmailJob::create([
            'campaign_id' => $campaign->id,
            'recipient_email' => 'concurrent@example.com',
            'status' => EmailJobStatus::Pending,
        ]);

        // Manually claim the job first (simulating another worker claiming it)
        $emailJob->update(['status' => EmailJobStatus::Processing]);

        $mockSender = $this->createMock(EmailSenderService::class);
        $mockSender->expects($this->never())->method('send');

        $job = new ProcessEmailJob($emailJob->id);
        $job->handle($mockSender);

        // Record should remain unaffected and sender was not called
        $emailJob->refresh();
        $this->assertEquals(EmailJobStatus::Processing, $emailJob->status);
    }

    public function test_campaign_transitions_to_processing_while_other_recipients_remain_pending(): void
    {
        $campaign = Campaign::create([
            'name' => 'Multi Recipient Test',
            'subject' => 'Subject',
            'body' => 'Body',
            'recipient_count' => 2,
        ]);
        $campaign->forceFill(['status' => CampaignStatus::Queued])->save();

        $job1 = EmailJob::create([
            'campaign_id' => $campaign->id,
            'recipient_email' => 'user1@example.com',
            'status' => EmailJobStatus::Pending,
        ]);

        $job2 = EmailJob::create([
            'campaign_id' => $campaign->id,
            'recipient_email' => 'user2@example.com',
            'status' => EmailJobStatus::Pending,
        ]);

        $sender = new EmailSenderService;
        $workerJob = new ProcessEmailJob($job1->id);
        $workerJob->handle($sender);

        $job1->refresh();
        $job2->refresh();
        $campaign->refresh();

        $this->assertEquals(EmailJobStatus::Sent, $job1->status);
        $this->assertEquals(EmailJobStatus::Pending, $job2->status);
        // Still has pending work, so campaign status is processing
        $this->assertEquals(CampaignStatus::Processing, $campaign->status);
    }

    public function test_permanent_failure_marks_recipient_failed_and_saves_error_message(): void
    {
        $campaign = Campaign::create([
            'name' => 'Failure Test',
            'subject' => 'Subject',
            'body' => 'Body',
            'recipient_count' => 1,
        ]);
        $campaign->forceFill(['status' => CampaignStatus::Queued])->save();

        $emailJob = EmailJob::create([
            'campaign_id' => $campaign->id,
            'recipient_email' => 'fail@example.com',
            'status' => EmailJobStatus::Pending,
        ]);

        $job = new ProcessEmailJob($emailJob->id);
        $job->failed(new Exception('Simulated SMTP permanent timeout'));

        $emailJob->refresh();
        $campaign->refresh();

        $this->assertEquals(EmailJobStatus::Failed, $emailJob->status);
        $this->assertStringContainsString('Simulated SMTP permanent timeout', $emailJob->error_message);
        // All recipients are now completed (sent or failed), so campaign status becomes Done
        $this->assertEquals(CampaignStatus::Done, $campaign->status);
    }

    public function test_campaign_status_becomes_done_with_mixed_outcomes(): void
    {
        $campaign = Campaign::create([
            'name' => 'Mixed Outcomes',
            'subject' => 'Subject',
            'body' => 'Body',
            'recipient_count' => 2,
        ]);
        $campaign->forceFill(['status' => CampaignStatus::Queued])->save();

        $job1 = EmailJob::create([
            'campaign_id' => $campaign->id,
            'recipient_email' => 'success@example.com',
            'status' => EmailJobStatus::Pending,
        ]);

        $job2 = EmailJob::create([
            'campaign_id' => $campaign->id,
            'recipient_email' => 'fail@example.com',
            'status' => EmailJobStatus::Pending,
        ]);

        $sender = new EmailSenderService;

        // Process first successfully
        $worker1 = new ProcessEmailJob($job1->id);
        $worker1->handle($sender);

        // Mark second as failed via terminal failure callback
        $worker2 = new ProcessEmailJob($job2->id);
        $worker2->failed(new Exception('Delivery rejected'));

        $campaign->refresh();
        $this->assertEquals(CampaignStatus::Done, $campaign->status);
    }
}
