<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // Per-chat disappearing-message timer, in seconds. null/0 = off.
            // When set, new messages in the chat expire this long after sending.
            $table->unsignedInteger('disappearing_seconds')->nullable()->after('type');
        });

        Schema::table('messages', function (Blueprint $table) {
            // When the message auto-deletes (null = never). Set at send time from
            // the conversation's disappearing_seconds, so later timer changes only
            // affect newer messages.
            $table->timestamp('expires_at')->nullable()->after('read_at');
            $table->index(['conversation_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'expires_at']);
            $table->dropColumn('expires_at');
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('disappearing_seconds');
        });
    }
};
