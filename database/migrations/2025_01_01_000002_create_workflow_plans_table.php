<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_plans', function (Blueprint $table) {
            $table->id();

            $table->string('session_id')->index();    // Links to Laravel session
            $table->text('user_intent')->nullable();  // Original user request text

            // Raw plan array from OrchestratorAgent
            $table->json('plan_steps');

            // pending / running / completed / failed
            $table->string('status')->default('pending');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_plans');
    }
};