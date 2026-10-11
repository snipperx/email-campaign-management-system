<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Done = 'done';
}
