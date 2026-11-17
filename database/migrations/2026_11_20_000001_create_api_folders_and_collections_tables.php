<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Folders nest to any depth through parent_id; a folder holds
        // sub-folders and API collections.
        Schema::create('api_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('api_folders')->cascadeOnDelete();
            $table->string('name', 160);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'parent_id']);
        });

        // A collection's requests (and its own request folders) are kept as
        // one JSON document, the same shape the desktop app imports and exports.
        Schema::create('api_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('api_folders')->cascadeOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->longText('items')->nullable();
            $table->text('variables')->nullable();
            $table->text('auth')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'folder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_collections');
        Schema::dropIfExists('api_folders');
    }
};
