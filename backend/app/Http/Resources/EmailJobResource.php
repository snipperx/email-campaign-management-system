<?php

namespace App\Http\Resources;

use App\Enums\EmailJobStatus;
use App\Models\EmailJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmailJob
 */
class EmailJobResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status === EmailJobStatus::Processing
            ? EmailJobStatus::Pending
            : $this->status;

        return [
            'id' => $this->id,
            'email' => $this->recipient_email,
            'status' => $status->value,
            'sent_at' => $this->sent_at?->toIso8601String(),
        ];
    }
}
