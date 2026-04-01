<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_plans', function (Blueprint $table) {
            $table->json('input_files')->nullable()->after('mood_board');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_plans', function (Blueprint $table) {
            $table->dropColumn('input_files');
        });
    }
};
