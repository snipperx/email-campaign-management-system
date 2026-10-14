<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureIdempotentRequest
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    /**
     * Replay the stored response when a request is retried with the same Idempotency-Key.
     *
     * Requests without the header are passed through untouched.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if ($key === null) {
            return $next($request);
        }

        $key = trim($key);

        if ($key === '' || mb_strlen($key) > 255) {
            return response()->json([
                'error' => 'Invalid input',
                'details' => [
                    self::HEADER => ['The Idempotency-Key header must be between 1 and 255 characters.'],
                ],
            ], 422);
        }

        $requestHash = self::fingerprint($request);
        $record = $this->acquire($key, $requestHash);

        if ($record instanceof Response) {
            return $record;
        }

        try {
            // Store the response in the same transaction as the request's writes, so a crash
            // can never leave a committed campaign behind without its replayable response.
            $response = DB::transaction(function () use ($request, $next, $record) {
                $response = $next($request);

                if ($response->isSuccessful()) {
                    $record->forceFill([
                        'response_status' => $response->getStatusCode(),
                        'response_body' => $response->getContent(),
                        'response_content_type' => $response->headers->get('Content-Type'),
                    ])->save();
                }

                return $response;
            });
        } catch (Throwable $e) {
            // Only release the key if nothing was committed. If the failure came after commit
            // (e.g. dispatching the queued jobs), the campaign exists and retries must replay
            // its stored response; the stalled-recipient sweeper re-dispatches the missing jobs.
            IdempotencyKey::query()
                ->whereKey($record->id)
                ->whereNull('response_status')
                ->delete();

            throw $e;
        }

        // Unsuccessful requests release the key so the client can retry with it
        if (! $response->isSuccessful()) {
            $record->delete();
        }

        return $response;
    }

    /**
     * Claim the key for this request, or return the response that answers it instead.
     */
    private function acquire(string $key, string $requestHash): IdempotencyKey|Response
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $inserted = IdempotencyKey::query()->insertOrIgnore([
                'key' => $key,
                'request_hash' => $requestHash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $existing = IdempotencyKey::query()->where('key', $key)->first();

            if ($inserted === 1 && $existing) {
                return $existing;
            }

            if (! $existing) {
                continue;
            }

            if ($existing->isStale()) {
                IdempotencyKey::query()->whereKey($existing->id)->delete();

                continue;
            }

            if (! hash_equals($existing->request_hash, $requestHash)) {
                return response()->json([
                    'error' => 'Idempotency-Key has already been used with a different request payload',
                ], 422);
            }

            if (! $existing->isCompleted()) {
                return response()->json([
                    'error' => 'A request with this Idempotency-Key is already being processed',
                ], 409);
            }

            return response($existing->response_body, $existing->response_status)
                ->header('Content-Type', $existing->response_content_type ?? 'application/json')
                ->header(self::REPLAYED_HEADER, 'true');
        }

        return response()->json([
            'error' => 'A request with this Idempotency-Key is already being processed',
        ], 409);
    }

    /**
     * Build a fingerprint of the request so a key cannot be reused for a different payload.
     */
    public static function fingerprint(Request $request): string
    {
        return hash('sha256', implode('|', [
            $request->method(),
            $request->path(),
            json_encode($request->all()),
        ]));
    }
}
