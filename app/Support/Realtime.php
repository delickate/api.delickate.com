<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pushes events to the socket server so every app sees changes instantly
 * (new, edited or pinned messages, read receipts, chat and friend changes),
 * whichever app made them.
 *
 * Events are collected during the request and sent in one call after the
 * response has gone out, so pushing never slows down the API response. If the
 * socket server is not configured or unreachable, apps still catch up through
 * their regular refresh.
 */
class Realtime
{
    /** @var array<int, array{event: string, user_ids: array<int, int>, payload: array}> */
    private static array $events = [];

    private static bool $flushScheduled = false;

    public static function enabled(): bool
    {
        return filled(config('services.socket.internal_url')) && filled(config('services.socket.internal_secret'));
    }

    /**
     * @param  iterable<int|string>  $userIds
     */
    public static function push(iterable $userIds, string $event, array $payload): void
    {
        if (! self::enabled()) {
            return;
        }
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values()->all();
        if ($ids === []) {
            return;
        }

        self::$events[] = ['event' => $event, 'user_ids' => $ids, 'payload' => $payload];
        if (! self::$flushScheduled) {
            self::$flushScheduled = true;
            app()->terminating(fn () => self::flush());
        }
    }

    public static function flush(): void
    {
        $events = self::$events;
        self::$events = [];
        self::$flushScheduled = false;
        if ($events === []) {
            return;
        }

        $url = rtrim((string) config('services.socket.internal_url'), '/').'/internal/events';
        foreach (array_chunk($events, 100) as $chunk) {
            try {
                Http::timeout(3)
                    ->acceptJson()
                    ->withToken((string) config('services.socket.internal_secret'))
                    ->post($url, ['events' => $chunk])
                    ->throw();
            } catch (\Throwable $exception) {
                Log::warning('Realtime push to the socket server failed: '.$exception->getMessage());
            }
        }
    }
}
