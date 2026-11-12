<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How far each person has read in each conversation. Unlike the single
        // read_at on a message, this tells us *who* has seen it, including in groups.
        Schema::create('conversation_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id');
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
        });

        // Backfill one-to-one chats from existing read receipts: a person has read
        // up to the newest message from the other side that is marked read.
        $now = now();
        DB::table('conversations')->where('type', 'direct')->orderBy('id')
            ->each(function ($conversation) use ($now) {
                foreach ([$conversation->low_user_id, $conversation->high_user_id] as $userId) {
                    if (! $userId) {
                        continue;
                    }
                    $lastRead = DB::table('messages')
                        ->where('conversation_id', $conversation->id)
                        ->where('sender_id', '!=', $userId)
                        ->whereNotNull('read_at')
                        ->orderByDesc('id')
                        ->first(['id', 'read_at']);
                    if ($lastRead) {
                        DB::table('conversation_reads')->insert([
                            'conversation_id' => $conversation->id,
                            'user_id' => $userId,
                            'last_read_message_id' => $lastRead->id,
                            'read_at' => $lastRead->read_at,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_reads');
    }
};
