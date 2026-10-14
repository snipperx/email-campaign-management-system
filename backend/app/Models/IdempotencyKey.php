<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class IdempotencyKey extends Model
{
    use Prunable;

    /**
     * How long a stored response may be replayed for a given key.
     */
    public const RETENTION_HOURS = 24;

    /**
     * How long an in-flight key blocks retries before it is treated as abandoned.
     */
    public const LOCK_TIMEOUT_SECONDS = 300;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'request_hash',
        'response_status',
        'response_body',
        'response_content_type',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
        ];
    }

    /**
     * Determine whether the original request has finished and its response is stored.
     */
    public function isCompleted(): bool
    {
        return $this->response_status !== null;
    }

    /**
     * Determine whether the key has outlived its replay window or its in-flight lock.
     */
    public function isStale(): bool
    {
        $ttl = $this->isCompleted() ? now()->subHours(self::RETENTION_HOURS) : now()->subSeconds(self::LOCK_TIMEOUT_SECONDS);

        return $this->created_at->lt($ttl);
    }

    /**
     * Get the prunable model query.
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subHours(self::RETENTION_HOURS));
    }
}
