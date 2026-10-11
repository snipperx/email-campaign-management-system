<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\EmailJobStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'subject',
        'body',
        'recipient_count',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'recipient_count' => 'integer',
        ];
    }

    /**
     * Get the email job records associated with the campaign.
     */
    public function emailJobs(): HasMany
    {
        return $this->hasMany(EmailJob::class)->orderBy('id');
    }

    /**
     * Re-calculate and update the campaign status based on recipient states.
     */
    public function recalculateStatus(): CampaignStatus
    {
        return app(\App\Actions\Campaigns\RecalculateCampaignStatusAction::class)->execute($this);
    }
}
