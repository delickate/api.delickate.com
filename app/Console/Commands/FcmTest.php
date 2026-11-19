<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Models\User;
use App\Support\Fcm;
use Illuminate\Console\Command;

class FcmTest extends Command
{
    protected $signature = 'fcm:test {user? : User id or phone number (omit to only verify credentials)}
        {--token= : Send to this raw FCM device token instead of a user\'s devices}
        {--title=DailyConnect}
        {--body=This is a test push notification.}';

    protected $description = "Verify FCM credentials and optionally send a test push to a user's devices.";

    public function handle(): int
    {
        if (! Fcm::enabled()) {
            $this->error('FCM is NOT configured. Set FCM_CREDENTIALS (absolute path to the service '
                .'account JSON, outside public/) and FCM_PROJECT_ID in .env, then run `php artisan config:clear`.');
            $this->line('services.fcm.credentials = '.var_export(config('services.fcm.credentials'), true));
            $this->line('services.fcm.project_id  = '.var_export(config('services.fcm.project_id'), true));

            return self::FAILURE;
        }

        // 1) Verify the service account can authenticate with Google (no device needed).
        try {
            Fcm::fetchAccessToken();
            $this->info('✓ FCM configured and service account authenticated with Google (OAuth token obtained).');
        } catch (\Throwable $exception) {
            $this->error('✗ FCM credentials are set but authentication failed: '.$exception->getMessage());
            $this->line('Check that FCM_CREDENTIALS points to the service account JSON (with private_key/client_email), '
                .'that the server clock is correct, and that the Cloud Messaging API is enabled for the project.');

            return self::FAILURE;
        }

        // 2) Optionally send a real test push.
        $rawToken = (string) ($this->option('token') ?? '');
        if ($rawToken !== '') {
            Fcm::sendTestToToken($rawToken, (string) $this->option('title'), (string) $this->option('body'));
            $this->info('Test push dispatched to the supplied token. Check the device and storage/logs/laravel.log.');

            return self::SUCCESS;
        }

        $arg = (string) ($this->argument('user') ?? '');
        if ($arg === '') {
            $this->line('Credentials OK. Pass a user id/phone or --token=<fcm-token> to send an actual test push.');

            return self::SUCCESS;
        }

        $user = User::query()->where('id', $arg)->orWhere('phone', $arg)->first();
        if (! $user) {
            $this->error("No user found with id or phone \"{$arg}\".");

            return self::FAILURE;
        }

        $count = DeviceToken::query()->where('user_id', $user->id)->count();
        if ($count === 0) {
            $this->warn("User {$user->id} ({$user->name}) has no registered devices. The app must POST its "
                .'FCM token to /api/user/device-tokens after sign-in first.');

            return self::FAILURE;
        }

        Fcm::notify([$user->id], (string) $this->option('title'), (string) $this->option('body'), ['type' => 'test']);
        Fcm::flush(); // Send immediately (a console command has no HTTP response to defer to).

        $this->info("Test push dispatched to {$count} device(s) for {$user->name} (id {$user->id}).");
        $this->line('Check the device, and storage/logs/laravel.log for any FCM errors (e.g. invalid tokens).');

        return self::SUCCESS;
    }
}
