<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('type')->default('direct')->after('id');
            $table->string('name')->nullable()->after('type');
            $table->foreignId('created_by_user_id')->nullable()->after('name')->constrained('users')->nullOnDelete();
            $table->foreignId('low_user_id')->nullable()->change();
            $table->foreignId('high_user_id')->nullable()->change();
        });

        Schema::create('conversation_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_users');

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['type', 'name', 'created_by_user_id']);
        });
    }
};
