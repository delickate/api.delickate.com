<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Unread counts now come from each member's read position. Group members had
     * no recorded position, so without this every group would suddenly show its
     * whole history as unread. Each member starts at the newest message that was
     * already read by someone, or that they sent themselves.
     */
    public function up(): void
    {
        $now = now();
        DB::table('conversations')->where('type', 'group')->orderBy('id')
            ->each(function ($conversation) use ($now) {
                $memberIds = DB::table('conversation_users')
                    ->where('conversation_id', $conversation->id)
                    ->pluck('user_id');
                foreach ($memberIds as $memberId) {
                    $lastSeenId = DB::table('messages')
                        ->where('conversation_id', $conversation->id)
                        ->where(fn ($query) => $query->whereNotNull('read_at')->orWhere('sender_id', $memberId))
                        ->max('id');
                    if (! $lastSeenId) {
                        continue;
                    }
                    DB::table('conversation_reads')->insertOrIgnore([
                        'conversation_id' => $conversation->id,
                        'user_id' => $memberId,
                        'last_read_message_id' => $lastSeenId,
                        'read_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Read positions created here are indistinguishable from real ones; nothing to undo.
    }
};
