<?php

namespace Tests\Feature;

use App\Actions\Campaigns\CreateCampaignAction;
use App\Actions\Campaigns\ProcessEmailRecipientAction;
use App\Actions\Campaigns\RecalculateCampaignStatusAction;
use App\Enums\CampaignStatus;
use App\Enums\EmailJobStatus;
use App\Jobs\ProcessEmailJob;
use App\Models\Campaign;
use App\Models\EmailJob;
use App\Services\EmailSenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_campaign_action_persists_records_and_dispatches_jobs(): void
    {
        Queue::fake();

        $action = app(CreateCampaignAction::class);
        $campaign = $action->execute([
            'name' => 'Spring Action',
            'subject' => 'Action Subject',
            'body' => 'Body text',
            'recipient_emails' => ['user1@example.com', 'user2@example.com'],
        ]);

        $this->assertInstanceOf(Campaign::class, $campaign);
        $this->assertEquals(CampaignStatus::Queued, $campaign->status);
        $this->assertEquals(2, $campaign->recipient_count);
        $this->assertDatabaseCount('email_jobs', 2);

        Queue::assertPushed(ProcessEmailJob::class, 2);
    }

    public function test_process_email_recipient_action_sends_email_and_marks_sent(): void
    {
        $campaign = Campaign::create([
            'name' => 'Test',
            'subject' => 'Subject',
            'body' => 'Body',
            'recipient_count' => 1,
        ]);
        $campaign->forceFill(['status' => CampaignStatus::Processing])->save();

        $emailJob = EmailJob::create([
            'campaign_id' => $campaign->id,
            'recipient_email' => 'action@example.com',
            'status' => EmailJobStatus::Processing,
        ]);

        $action = app(ProcessEmailRecipientAction::class);
        $action->execute($emailJob);

        $emailJob->refresh();
        $campaign->refresh();

        $this->assertEquals(EmailJobStatus::Sent, $emailJob->status);
        $this->assertNotNull($emailJob->sent_at);
        $this->assertEquals(CampaignStatus::Done, $campaign->status);
    }

    public function test_recalculate_campaign_status_action_calculates_correct_status(): void
    {
        $campaign = Campaign::create([
            'name' => 'Recalculate Test',
            'subject' => 'Subject',
            'body' => 'Body',
            'recipient_count' => 2,
        ]);
        $campaign->forceFill(['status' => CampaignStatus::Queued])->save();

        $job1 = EmailJob::create([
            'campaign_id' => $campaign->id,
            'recipient_email' => 'one@example.com',
            'status' => EmailJobStatus::Sent,
            'sent_at' => now(),
        ]);

        $job2 = EmailJob::create([
            'campaign_id' => $campaign->id,
            'recipient_email' => 'two@example.com',
            'status' => EmailJobStatus::Pending,
        ]);

        $action = app(RecalculateCampaignStatusAction::class);

        // One sent, one pending -> should be Processing
        $status = $action->execute($campaign);
        $this->assertEquals(CampaignStatus::Processing, $status);

        // Update second to Failed -> should become Done
        $job2->update(['status' => EmailJobStatus::Failed]);
        $status = $action->execute($campaign);
        $this->assertEquals(CampaignStatus::Done, $status);
    }
}
