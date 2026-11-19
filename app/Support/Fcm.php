<?php

namespace App\Support;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notifications through Firebase Cloud Messaging (HTTP v1).
 *
 * Auth uses the Firebase *service account* JSON (config services.fcm.credentials)
 * to mint a short-lived OAuth2 access token — no third-party package needed.
 * Like Realtime, notifications are queued during the request and sent after the
 * response is flushed, so push never slows the API down. Invalid/expired device
 * tokens are pruned automatically.
 */
class Fcm
{
    /** @var array<int, array{user_ids: array<int,int>, title: string, body: string, data: array}> */
    private static array $queued = [];

    private static bool $flushScheduled = false;

    public static function enabled(): bool
    {
        $path = config('services.fcm.credentials');

        return filled($path)
            && filled(config('services.fcm.project_id'))
            && is_string($path) && is_file($path) && is_readable($path);
    }

    /**
     * Queue a push to every device of the given users (deferred to after response).
     *
     * @param  iterable<int|string>  $userIds
     */
    public static function notify(iterable $userIds, string $title, string $body, array $data = []): void
    {
        if (! self::enabled()) {
            return;
        }
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values()->all();
        if ($ids === []) {
            return;
        }

        self::$queued[] = ['user_ids' => $ids, 'title' => $title, 'body' => $body, 'data' => $data];
        if (! self::$flushScheduled) {
            self::$flushScheduled = true;
            app()->terminating(fn () => self::flush());
        }
    }

    /**
     * Obtains an OAuth2 access token from the configured service account.
     * Throws on failure — used by `fcm:test` to verify credentials without a device.
     */
    public static function fetchAccessToken(): string
    {
        return (string) self::accessToken();
    }

    /** Sends a single push to a raw device token immediately (used by fcm:test). */
    public static function sendTestToToken(string $token, string $title, string $body): void
    {
        if (! self::enabled()) {
            return;
        }
        $accessToken = self::accessToken();
        if (! $accessToken) {
            return;
        }
        $projectId = (string) config('services.fcm.project_id');
        self::send(
            "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send",
            $accessToken,
            $token,
            $title,
            $body,
            ['type' => 'test'],
        );
    }

    public static function flush(): void
    {
        $queued = self::$queued;
        self::$queued = [];
        self::$flushScheduled = false;
        if ($queued === [] || ! self::enabled()) {
            return;
        }

        try {
            $accessToken = self::accessToken();
        } catch (\Throwable $exception) {
            Log::warning('FCM access token could not be obtained: '.$exception->getMessage());

            return;
        }
        if (! $accessToken) {
            return;
        }

        $projectId = (string) config('services.fcm.project_id');
        $endpoint = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        foreach ($queued as $item) {
            $tokens = DeviceToken::query()->whereIn('user_id', $item['user_ids'])->pluck('token')->unique()->all();
            foreach ($tokens as $token) {
                self::send($endpoint, $accessToken, (string) $token, $item['title'], $item['body'], $item['data']);
            }
        }
    }

    private static function send(string $endpoint, string $accessToken, string $token, string $title, string $body, array $data): void
    {
        // FCM v1 requires all data values to be strings.
        $stringData = [];
        foreach ($data as $key => $value) {
            $stringData[(string) $key] = (string) $value;
        }

        $payload = ['message' => [
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => $stringData,
            'android' => [
                'priority' => 'high',
                'notification' => ['sound' => 'default', 'channel_id' => 'messages'],
            ],
            'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
        ]];

        try {
            $response = Http::timeout(5)->acceptJson()->withToken($accessToken)->post($endpoint, $payload);
            if ($response->failed()) {
                $status = (string) $response->json('error.status');
                // A token that is gone/invalid will never work again — drop it.
                if ($response->status() === 404 || in_array($status, ['NOT_FOUND', 'UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
                    DeviceToken::query()->where('token', $token)->delete();
                }
                Log::warning('FCM send failed ('.$response->status().'): '.$response->body());
            }
        } catch (\Throwable $exception) {
            Log::warning('FCM send threw: '.$exception->getMessage());
        }
    }

    private static function accessToken(): ?string
    {
        return Cache::remember('fcm_access_token', 3300, function () {
            $creds = json_decode((string) file_get_contents((string) config('services.fcm.credentials')), true);
            if (! is_array($creds) || empty($creds['client_email']) || empty($creds['private_key'])) {
                throw new \RuntimeException('FCM service account JSON is missing client_email/private_key. '
                    .'Use the service account key, not google-services.json.');
            }

            $tokenUri = $creds['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $now = time();
            $jwt = self::signJwt([
                'iss' => $creds['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ], (string) $creds['private_key']);

            $response = Http::asForm()->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
            if ($response->failed()) {
                throw new \RuntimeException('OAuth token exchange failed: '.$response->body());
            }

            return (string) $response->json('access_token');
        });
    }

    private static function signJwt(array $claims, string $privateKey): string
    {
        $segments = [
            self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64Url((string) json_encode($claims)),
        ];
        $signingInput = implode('.', $segments);
        $signature = '';
        if (! openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to sign the FCM JWT with the service account key.');
        }
        $segments[] = self::base64Url($signature);

        return implode('.', $segments);
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
