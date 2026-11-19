<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Models\User;
use App\Support\Fcm;
use Illuminate\Console\Command;

class FcmTest extends Command
{
    protected $signature = 'fcm:test {user : User id or phone number}
        {--title=DailyConnect}
        {--body=This is a test push notification.}';

    protected $description = "Send a test push notification to a user's registered devices.";

    public function handle(): int
    {
        if (! Fcm::enabled()) {
            $this->error('FCM is not configured. Set FCM_CREDENTIALS (absolute path to the service '
                .'account JSON, outside public/) and FCM_PROJECT_ID in .env, then run `php artisan config:clear`.');

            return self::FAILURE;
        }

        $arg = (string) $this->argument('user');
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
