<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('call_id', 36)->nullable()->unique();
            $table->string('call_status', 16)->nullable();
            $table->string('call_media_type', 8)->nullable();
            $table->unsignedInteger('call_duration_seconds')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['call_id']);
            $table->dropColumn([
                'call_id',
                'call_status',
                'call_media_type',
                'call_duration_seconds',
            ]);
        });
    }
};
