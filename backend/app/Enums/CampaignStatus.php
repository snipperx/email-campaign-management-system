<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Done = 'done';

    /**
     * Campaign statuses only ever move forward: queued -> processing -> done.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Processing => 1,
            self::Done => 2,
        };
    }

    /**
     * Get the raw values of statuses that may legitimately transition to this one.
     *
     * @return array<int, string>
     */
    public function precedingValues(): array
    {
        return array_values(array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->rank() < $this->rank()),
        ));
    }
}
