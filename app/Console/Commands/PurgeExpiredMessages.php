<?php

namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeExpiredMessages extends Command
{
    protected $signature = 'messages:purge-expired {--chunk=500}';

    protected $description = 'Delete disappearing messages whose timer has passed, with their attachments.';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $deleted = 0;

        Message::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->select(['id', 'attachment_path'])
            ->chunkById($chunk, function ($messages) use (&$deleted) {
                foreach ($messages as $message) {
                    if ($message->attachment_path) {
                        Storage::disk('private')->delete($message->attachment_path);
                    }
                }
                $deleted += Message::query()->whereKey($messages->pluck('id'))->delete();
            });

        $this->info("Purged {$deleted} expired message(s).");

        return self::SUCCESS;
    }
}
