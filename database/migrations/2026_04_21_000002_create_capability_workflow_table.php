<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('capability_workflow', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capability_id')->constrained()->onDelete('cascade');
            $table->foreignId('workflow_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            
            // Ensure unique capability-workflow pairs
            $table->unique(['capability_id', 'workflow_id']);
            
            $table->index('capability_id');
            $table->index('workflow_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capability_workflow');
    }
};
