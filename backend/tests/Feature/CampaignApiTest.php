<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\EmailJobStatus;
use App\Jobs\ProcessEmailJob;
use App\Models\Campaign;
use App\Models\EmailJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_campaign_with_valid_data(): void
    {
        Queue::fake();

        $payload = [
            'name' => 'Spring Sale',
            'subject' => '50% Off This Weekend!',
            'body' => 'Check out our amazing deals...',
            'recipient_emails' => [
                'john@example.com',
                'mary@example.com',
            ],
        ];

        $response = $this->postJson('/api/campaigns', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'recipient_count' => 2,
                'status' => 'queued',
            ])
            ->assertJsonStructure([
                'campaign_id',
                'recipient_count',
                'status',
            ]);

        $campaignId = $response->json('campaign_id');
        $this->assertDatabaseHas('campaigns', [
            'id' => $campaignId,
            'name' => 'Spring Sale',
            'subject' => '50% Off This Weekend!',
            'body' => 'Check out our amazing deals...',
            'recipient_count' => 2,
            'status' => CampaignStatus::Queued->value,
        ]);

        $this->assertDatabaseCount('email_jobs', 2);
        $this->assertDatabaseHas('email_jobs', [
            'campaign_id' => $campaignId,
            'recipient_email' => 'john@example.com',
            'status' => EmailJobStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('email_jobs', [
            'campaign_id' => $campaignId,
            'recipient_email' => 'mary@example.com',
            'status' => EmailJobStatus::Pending->value,
        ]);

        Queue::assertPushed(ProcessEmailJob::class, 2);
    }

    public function test_validation_fails_when_required_fields_are_missing(): void
    {
        $response = $this->postJson('/api/campaigns', []);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'Invalid input',
            ])
            ->assertJsonStructure([
                'error',
                'details' => [
                    'name',
                    'subject',
                    'body',
                    'recipient_emails',
                ],
            ]);
    }

    public function test_validation_fails_for_invalid_email_format(): void
    {
        $payload = [
            'name' => 'Newsletter',
            'subject' => 'Weekly Digest',
            'body' => 'Content here',
            'recipient_emails' => ['not-a-valid-email'],
        ];

        $response = $this->postJson('/api/campaigns', $payload);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'Invalid input',
            ])
            ->assertJsonStructure([
                'error',
                'details' => [
                    'recipient_emails.0',
                ],
            ]);
    }

    public function test_validation_fails_for_duplicate_emails_case_insensitively(): void
    {
        $payload = [
            'name' => 'Product Announcement',
            'subject' => 'Exciting News',
            'body' => 'Our new product is live!',
            'recipient_emails' => [
                'user@example.com',
                'USER@EXAMPLE.COM',
            ],
        ];

        $response = $this->postJson('/api/campaigns', $payload);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'Invalid input',
                'details' => [
                    'recipient_emails.1' => [
                        'Duplicate recipient email addresses are not allowed.',
                    ],
                ],
            ]);
    }

    public function test_validation_fails_for_empty_recipient_list(): void
    {
        $payload = [
            'name' => 'Newsletter',
            'subject' => 'Weekly Digest',
            'body' => 'Content here',
            'recipient_emails' => [],
        ];

        $response = $this->postJson('/api/campaigns', $payload);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'Invalid input',
            ])
            ->assertJsonStructure([
                'error',
                'details' => [
                    'recipient_emails',
                ],
            ]);
    }

    public function test_normalises_surrounding_whitespace_on_recipient_emails(): void
    {
        Queue::fake();

        $payload = [
            'name' => '  Whitespace Test  ',
            'subject' => '  Subject with spaces  ',
            'body' => 'Body text',
            'recipient_emails' => [
                '  user1@example.com  ',
                '  user2@example.com  ',
            ],
        ];

        $response = $this->postJson('/api/campaigns', $payload);

        $response->assertStatus(201);
        $campaignId = $response->json('campaign_id');

        $this->assertDatabaseHas('campaigns', [
            'id' => $campaignId,
            'name' => 'Whitespace Test',
            'subject' => 'Subject with spaces',
        ]);

        $this->assertDatabaseHas('email_jobs', [
            'campaign_id' => $campaignId,
            'recipient_email' => 'user1@example.com',
        ]);
        $this->assertDatabaseHas('email_jobs', [
            'campaign_id' => $campaignId,
            'recipient_email' => 'user2@example.com',
        ]);
    }

    public function test_lists_campaigns_ordered_newest_first(): void
    {
        $oldCampaign = Campaign::create([
            'name' => 'Old Campaign',
            'subject' => 'Old Subject',
            'body' => 'Old Body',
            'recipient_count' => 1,
            'created_at' => now()->subDay(),
        ]);
        $oldCampaign->forceFill(['status' => CampaignStatus::Done])->save();

        $newCampaign = Campaign::create([
            'name' => 'New Campaign',
            'subject' => 'New Subject',
            'body' => 'New Body',
            'recipient_count' => 5,
            'created_at' => now(),
        ]);
        $newCampaign->forceFill(['status' => CampaignStatus::Processing])->save();

        $response = $this->getJson('/api/campaigns');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'subject',
                        'recipient_count',
                        'status',
                        'created_at',
                    ],
                ],
            ]);

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals($newCampaign->id, $data[0]['id']);
        $this->assertEquals($oldCampaign->id, $data[1]['id']);
    }

    public function test_can_retrieve_campaign_detail_with_recipients(): void
    {
        $campaign = Campaign::create([
            'name' => 'Detailed Campaign',
            'subject' => 'Detailed Subject',
            'body' => 'Detailed Content Body',
            'recipient_count' => 2,
        ]);
        $campaign->forceFill(['status' => CampaignStatus::Processing])->save();

        $job1 = EmailJob::create([
            'campaign_id' => $campaign->id,
            'recipient_email' => 'alice@example.com',
            'status' => EmailJobStatus::Sent,
            'sent_at' => now(),
        ]);

        $job2 = EmailJob::create([
            'campaign_id' => $campaign->id,
            'recipient_email' => 'bob@example.com',
            'status' => EmailJobStatus::Pending,
            'sent_at' => null,
        ]);

        $response = $this->getJson("/api/campaigns/{$campaign->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $campaign->id,
                    'name' => 'Detailed Campaign',
                    'subject' => 'Detailed Subject',
                    'body' => 'Detailed Content Body',
                    'recipient_count' => 2,
                    'status' => 'processing',
                    'recipients' => [
                        [
                            'id' => $job1->id,
                            'email' => 'alice@example.com',
                            'status' => 'sent',
                        ],
                        [
                            'id' => $job2->id,
                            'email' => 'bob@example.com',
                            'status' => 'pending',
                            'sent_at' => null,
                        ],
                    ],
                ],
            ]);
    }

    public function test_processing_recipient_is_returned_as_pending_for_the_frontend(): void
    {
        $campaign = Campaign::create([
            'name' => 'Processing Campaign',
            'subject' => 'Processing Subject',
            'body' => 'Processing Body',
            'recipient_count' => 1,
        ]);
        $campaign->forceFill(['status' => CampaignStatus::Processing])->save();

        EmailJob::create([
            'campaign_id' => $campaign->id,
            'recipient_email' => 'processing@example.com',
            'status' => EmailJobStatus::Processing,
        ]);

        $this->getJson("/api/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertJsonPath('data.recipients.0.email', 'processing@example.com')
            ->assertJsonPath('data.recipients.0.status', 'pending');
    }

    public function test_returns_404_when_campaign_not_found(): void
    {
        $response = $this->getJson('/api/campaigns/999999');

        $response->assertStatus(404)
            ->assertJson([
                'error' => 'Campaign not found',
            ]);
    }
}
