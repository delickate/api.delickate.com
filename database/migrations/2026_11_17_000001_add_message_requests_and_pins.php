<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // Chats started with someone who is not a friend: "pending" until the
            // recipient accepts (or replies), "declined" if they decline.
            $table->string('request_status', 16)->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable();
            $table->foreignId('pinned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['conversation_id', 'pinned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'pinned_at']);
            $table->dropConstrainedForeignId('pinned_by_user_id');
            $table->dropColumn('pinned_at');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by_user_id');
            $table->dropColumn('request_status');
        });
    }
};
