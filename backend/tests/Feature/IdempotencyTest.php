<?php

namespace Tests\Feature;

use App\Actions\Campaigns\RecalculateCampaignStatusAction;
use App\Actions\Campaigns\RecoverStalledEmailJobsAction;
use App\Enums\CampaignStatus;
use App\Enums\EmailJobStatus;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Jobs\ProcessEmailJob;
use App\Models\Campaign;
use App\Models\EmailJob;
use App\Models\IdempotencyKey;
use App\Services\EmailSenderService;
use Exception;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{name: string, subject: string, body: string, recipient_emails: array<int, string>}
     */
    private function payload(): array
    {
        return [
            'name' => 'Spring Sale',
            'subject' => '50% Off',
            'body' => 'Deals inside',
            'recipient_emails' => ['john@example.com', 'mary@example.com'],
        ];
    }

    private function createCampaignWithRecipient(EmailJobStatus $status, CampaignStatus $campaignStatus = CampaignStatus::Processing): EmailJob
    {
        $campaign = Campaign::create([
            'name' => 'Campaign',
            'subject' => 'Subject',
            'body' => 'Body',
            'recipient_count' => 1,
        ]);
        $campaign->forceFill(['status' => $campaignStatus])->save();

        return EmailJob::create([
            'campaign_id' => $campaign->id,
            'recipient_email' => 'recipient@example.com',
            'status' => $status,
        ]);
    }

    private function fakeQueueJob(int $attempts, string $uuid): Job
    {
        $queueJob = $this->createMock(Job::class);
        $queueJob->method('attempts')->willReturn($attempts);
        $queueJob->method('uuid')->willReturn($uuid);

        return $queueJob;
    }

    public function test_retried_request_with_same_key_replays_response_without_duplicating_campaign(): void
    {
        Queue::fake();

        $headers = ['Idempotency-Key' => 'create-spring-sale'];

        $first = $this->postJson('/api/campaigns', $this->payload(), $headers);
        $second = $this->postJson('/api/campaigns', $this->payload(), $headers);

        $first->assertStatus(201)->assertHeaderMissing('Idempotent-Replayed');
        $second->assertStatus(201)
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertExactJson($first->json());

        $this->assertDatabaseCount('campaigns', 1);
        $this->assertDatabaseCount('email_jobs', 2);
        Queue::assertPushed(ProcessEmailJob::class, 2);
    }

    public function test_requests_without_key_are_not_deduplicated(): void
    {
        Queue::fake();

        $this->postJson('/api/campaigns', $this->payload())->assertStatus(201);
        $this->postJson('/api/campaigns', $this->payload())->assertStatus(201);

        $this->assertDatabaseCount('campaigns', 2);
        $this->assertDatabaseCount('idempotency_keys', 0);
    }

    public function test_reusing_key_with_different_payload_is_rejected(): void
    {
        Queue::fake();

        $headers = ['Idempotency-Key' => 'reused-key'];

        $this->postJson('/api/campaigns', $this->payload(), $headers)->assertStatus(201);

        $this->postJson('/api/campaigns', [...$this->payload(), 'name' => 'Other campaign'], $headers)
            ->assertStatus(422)
            ->assertJsonStructure(['error']);

        $this->assertDatabaseCount('campaigns', 1);
    }

    public function test_key_in_flight_returns_conflict(): void
    {
        Queue::fake();

        IdempotencyKey::create([
            'key' => 'in-flight-key',
            'request_hash' => EnsureIdempotentRequest::fingerprint(
                Request::create('/api/campaigns', 'POST', $this->payload())
            ),
        ]);

        $this->postJson('/api/campaigns', $this->payload(), ['Idempotency-Key' => 'in-flight-key'])
            ->assertStatus(409);

        $this->assertDatabaseCount('campaigns', 0);
    }

    public function test_abandoned_in_flight_key_can_be_taken_over(): void
    {
        Queue::fake();

        $stale = IdempotencyKey::create([
            'key' => 'abandoned-key',
            'request_hash' => str_repeat('a', 64),
        ]);
        $stale->forceFill(['created_at' => now()->subSeconds(IdempotencyKey::LOCK_TIMEOUT_SECONDS + 1)])->save();

        $this->postJson('/api/campaigns', $this->payload(), ['Idempotency-Key' => 'abandoned-key'])
            ->assertStatus(201);

        $this->assertDatabaseCount('campaigns', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_failed_validation_releases_key_for_retry(): void
    {
        Queue::fake();

        $headers = ['Idempotency-Key' => 'retry-after-fix'];

        $this->postJson('/api/campaigns', [...$this->payload(), 'recipient_emails' => ['not-an-email']], $headers)
            ->assertStatus(422);
        $this->assertDatabaseCount('idempotency_keys', 0);

        $this->postJson('/api/campaigns', $this->payload(), $headers)->assertStatus(201);
        $this->assertDatabaseCount('campaigns', 1);
    }

    public function test_overlong_key_is_rejected(): void
    {
        $this->postJson('/api/campaigns', $this->payload(), ['Idempotency-Key' => str_repeat('k', 256)])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Invalid input');
    }

    public function test_expired_keys_are_prunable(): void
    {
        $expired = IdempotencyKey::create(['key' => 'old', 'request_hash' => str_repeat('a', 64)]);
        $expired->forceFill(['created_at' => now()->subHours(IdempotencyKey::RETENTION_HOURS + 1)])->save();
        IdempotencyKey::create(['key' => 'fresh', 'request_hash' => str_repeat('a', 64)]);

        $this->artisan('model:prune', ['--model' => IdempotencyKey::class])->assertSuccessful();

        $this->assertDatabaseMissing('idempotency_keys', ['key' => 'old']);
        $this->assertDatabaseHas('idempotency_keys', ['key' => 'fresh']);
    }

    public function test_terminal_failure_does_not_downgrade_sent_recipient(): void
    {
        $emailJob = $this->createCampaignWithRecipient(EmailJobStatus::Sent, CampaignStatus::Done);

        (new ProcessEmailJob($emailJob->id))->failed(new Exception('Timed out after send'));

        $emailJob->refresh();
        $this->assertEquals(EmailJobStatus::Sent, $emailJob->status);
        $this->assertNull($emailJob->error_message);
    }

    public function test_retry_cannot_reclaim_record_owned_by_another_queued_job(): void
    {
        $emailJob = $this->createCampaignWithRecipient(EmailJobStatus::Processing);
        $emailJob->forceFill(['claim_token' => 'original-job-uuid'])->save();

        $mockSender = $this->createMock(EmailSenderService::class);
        $mockSender->expects($this->never())->method('send');

        $duplicate = new ProcessEmailJob($emailJob->id);
        $duplicate->setJob($this->fakeQueueJob(2, 'duplicate-job-uuid'));
        $duplicate->handle($mockSender);

        $this->assertEquals(EmailJobStatus::Processing, $emailJob->refresh()->status);
    }

    public function test_retry_reclaims_record_it_claimed_itself(): void
    {
        $emailJob = $this->createCampaignWithRecipient(EmailJobStatus::Processing);
        $emailJob->forceFill(['claim_token' => 'original-job-uuid'])->save();

        $retry = new ProcessEmailJob($emailJob->id);
        $retry->setJob($this->fakeQueueJob(2, 'original-job-uuid'));
        $retry->handle(new EmailSenderService);

        $this->assertEquals(EmailJobStatus::Sent, $emailJob->refresh()->status);
        $this->assertEquals(CampaignStatus::Done, $emailJob->campaign->refresh()->status);
    }

    public function test_stale_recalculation_cannot_move_campaign_status_backwards(): void
    {
        $emailJob = $this->createCampaignWithRecipient(EmailJobStatus::Sent, CampaignStatus::Done);
        $campaign = $emailJob->campaign;

        // Simulate a slower worker whose view still has active work
        $emailJob->update(['status' => EmailJobStatus::Processing]);

        $status = app(RecalculateCampaignStatusAction::class)->execute($campaign);

        $this->assertEquals(CampaignStatus::Done, $status);
        $this->assertEquals(CampaignStatus::Done, $campaign->refresh()->status);
    }

    public function test_database_rejects_duplicate_recipient_within_campaign(): void
    {
        $emailJob = $this->createCampaignWithRecipient(EmailJobStatus::Pending);

        $this->expectException(UniqueConstraintViolationException::class);

        EmailJob::create([
            'campaign_id' => $emailJob->campaign_id,
            'recipient_email' => $emailJob->recipient_email,
            'status' => EmailJobStatus::Pending,
        ]);
    }

    public function test_failure_after_commit_keeps_key_so_retry_replays_instead_of_duplicating(): void
    {
        Queue::fake();

        // Simulate the queue being unavailable when jobs are dispatched after commit
        $failDispatch = true;
        Campaign::created(function () use (&$failDispatch) {
            DB::afterCommit(function () use (&$failDispatch) {
                if ($failDispatch) {
                    throw new RuntimeException('Queue unavailable');
                }
            });
        });

        $headers = ['Idempotency-Key' => 'queue-outage'];

        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/campaigns', $this->payload(), $headers);
            $this->fail('Expected the dispatch failure to bubble up');
        } catch (RuntimeException $e) {
            $this->assertSame('Queue unavailable', $e->getMessage());
        }

        $failDispatch = false;
        $this->postJson('/api/campaigns', $this->payload(), $headers)
            ->assertStatus(201)
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertDatabaseCount('campaigns', 1);
    }

    private function backdate(EmailJob $emailJob, int $minutes): void
    {
        DB::table('email_jobs')->where('id', $emailJob->id)->update(['updated_at' => now()->subMinutes($minutes)]);
    }

    public function test_sweeper_redispatches_stalled_pending_and_processing_recipients(): void
    {
        Queue::fake();

        $pending = $this->createCampaignWithRecipient(EmailJobStatus::Pending);
        $processing = $this->createCampaignWithRecipient(EmailJobStatus::Processing);
        $processing->forceFill(['claim_token' => 'dead-worker'])->save();
        $this->backdate($pending, RecoverStalledEmailJobsAction::STALE_AFTER_MINUTES + 1);
        $this->backdate($processing, RecoverStalledEmailJobsAction::STALE_AFTER_MINUTES + 1);

        $this->artisan('campaigns:recover-stalled')
            ->expectsOutput('Re-dispatched 2 stalled recipient(s).')
            ->assertSuccessful();

        Queue::assertPushed(ProcessEmailJob::class, 2);
        $processing->refresh();
        $this->assertEquals(EmailJobStatus::Pending, $processing->status);
        $this->assertNull($processing->claim_token);
    }

    public function test_sweeper_ignores_recent_and_finished_recipients(): void
    {
        Queue::fake();

        $this->createCampaignWithRecipient(EmailJobStatus::Pending);
        $sent = $this->createCampaignWithRecipient(EmailJobStatus::Sent);
        $this->backdate($sent, RecoverStalledEmailJobsAction::STALE_AFTER_MINUTES + 1);

        $this->assertSame(0, app(RecoverStalledEmailJobsAction::class)->execute());
        Queue::assertNothingPushed();
    }

    public function test_sweeper_does_not_redispatch_the_same_recipient_on_consecutive_runs(): void
    {
        Queue::fake();

        $pending = $this->createCampaignWithRecipient(EmailJobStatus::Pending);
        $this->backdate($pending, RecoverStalledEmailJobsAction::STALE_AFTER_MINUTES + 1);

        $action = app(RecoverStalledEmailJobsAction::class);
        $this->assertSame(1, $action->execute());
        $this->assertSame(0, $action->execute());
    }
}
