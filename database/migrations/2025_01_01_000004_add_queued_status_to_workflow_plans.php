<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_plans', function (Blueprint $table) {
            // Add queue_position for ordering backlog items
            $table->unsignedInteger('queue_position')->nullable()->after('status');
            // Add mood_board data — colors/styles picked during minigame
            $table->json('mood_board')->nullable()->after('queue_position');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_plans', function (Blueprint $table) {
            $table->dropColumn(['queue_position', 'mood_board']);
        });
    }
};