<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional picture for a group chat (path on the private disk).
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('icon_path')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('icon_path');
        });
    }
};
