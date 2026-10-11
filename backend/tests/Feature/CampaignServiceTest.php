<?php

namespace Tests\Feature;

use App\Models\EmailJob;
use App\Services\CampaignService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CampaignServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_rolls_back_and_persists_no_records_on_failure(): void
    {
        $service = app(CampaignService::class);

        // Simulate a failure during creation by hooking into creating event or mocking DB
        EmailJob::saving(function () {
            throw new Exception('Simulated database write error during transaction');
        });

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Simulated database write error during transaction');

        try {
            $service->createCampaign([
                'name' => 'Rollback Test',
                'subject' => 'Subject',
                'body' => 'Body text',
                'recipient_emails' => ['user@example.com'],
            ]);
        } finally {
            $this->assertDatabaseCount('campaigns', 0);
            $this->assertDatabaseCount('email_jobs', 0);
        }
    }
}
