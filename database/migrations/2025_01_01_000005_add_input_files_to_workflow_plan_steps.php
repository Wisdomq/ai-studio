<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add input_files JSON column to workflow_plan_steps.
 *
 * Stores a media-type → storage-path map so a single step can carry
 * multiple input files — e.g. {"image": "comfyui-inputs/a.png", "audio": "comfyui-inputs/b.mp3"}.
 *
 * The legacy input_file_path (single string) column is intentionally left
 * in place for backward compatibility. ExecutePlanJob::collectDependencyFiles()
 * reads input_files first and falls back to input_file_path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_plan_steps', function (Blueprint $table) {
            // Placed after the existing input_file_path column
            $table->json('input_files')->nullable()->after('input_file_path');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_plan_steps', function (Blueprint $table) {
            $table->dropColumn('input_files');
        });
    }
};