<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One random AES-256 key per conversation and version. Clients encrypt
        // message content with it before upload; the key itself is stored here
        // encrypted with the application key, never in plain form.
        Schema::create('conversation_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('key_material');
            $table->timestamps();

            $table->unique(['conversation_id', 'version']);
        });

        Schema::table('messages', function (Blueprint $table) {
            // Null for messages sent before client-side encryption.
            $table->unsignedInteger('key_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('key_version');
        });

        Schema::dropIfExists('conversation_keys');
    }
};
