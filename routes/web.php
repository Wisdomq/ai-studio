<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\StudioController;
use Illuminate\Support\Facades\Route;

// ─── Root ─────────────────────────────────────────────────────────────────────
Route::get('/', fn () => redirect()->route('studio.index'));

// ─── Studio Pipeline ──────────────────────────────────────────────────────────


    // Views
Route::get('/studio',             [StudioController::class, 'index'])->name('studio.index');
Route::get('/studio/generations',  [StudioController::class, 'generations'])->name('studio.generations');
Route::get('/studio/plan/{plan}/result', [StudioController::class, 'result'])->name('studio.result');
Route::delete('/studio/plan/{plan}', [StudioController::class, 'destroy'])->name('studio.plan.destroy');

    // Phase 1 — Planning
Route::post('/studio/planner',      [StudioController::class, 'planner'])->name('studio.planner');
Route::post('/studio/plan/approve', [StudioController::class, 'approvePlan'])->name('studio.plan.approve');

    // Phase 2 — Prompt Refinement
Route::post('/studio/plan/refine-step',                    [StudioController::class, 'refineStep'])->name('studio.plan.refine-step');
Route::post('/studio/plan/{plan}/step/{order}/confirm',    [StudioController::class, 'confirmStep'])->name('studio.plan.step.confirm');

    // Phase 3 — Execution
Route::get('/studio/plan/{plan}/review',                   [StudioController::class, 'review'])->name('studio.plan.review');
Route::post('/studio/plan/{plan}/dispatch',                [StudioController::class, 'dispatch'])->name('studio.plan.dispatch');
Route::post('/studio/plan/{plan}/queue',                   [StudioController::class, 'queuePlan'])->name('studio.plan.queue');
Route::post('/studio/plan/{plan}/dispatch-from-queue',     [StudioController::class, 'dispatchFromQueue'])->name('studio.plan.dispatch-from-queue');
Route::get('/studio/plan/{plan}/status',                   [StudioController::class, 'status'])->name('studio.plan.status');
Route::post('/studio/plan/{plan}/step/{order}/approve',    [StudioController::class, 'approveStep'])->name('studio.plan.step.approve');
Route::post('/studio/plan/{plan}/step/{order}/reject',     [StudioController::class, 'rejectStep'])->name('studio.plan.step.reject');

// Cancel a running step (user-facing + admin)
Route::post('/studio/plan/{plan}/step/{order}/cancel', [StudioController::class, 'cancelStep'])
    ->name('studio.plan.step.cancel');
 
// ComfyUI queue depth — polled every 10s during execution phase
Route::get('/studio/queue-status', [StudioController::class, 'queueStatus'])
    ->name('studio.queue-status');

// ComfyUI health check — polled by frontend LED indicator
Route::get('/studio/comfy-health', [StudioController::class, 'comfyHealth'])
    ->name('studio.comfy-health');

// Jobs panel — all session jobs
Route::get('/studio/jobs',                                 [StudioController::class, 'jobs'])->name('studio.jobs');
Route::get('/studio/queue-status',                         [StudioController::class, 'queueStatus'])->name('studio.queue-status');
Route::post('/studio/queue/run-next',                      [StudioController::class, 'runNextInQueue'])->name('studio.queue.run-next');
Route::post('/studio/plan/{plan}/mood-board',              [StudioController::class, 'saveMoodBoard'])->name('studio.plan.mood-board');

    // File upload
Route::post('/studio/upload', [StudioController::class, 'upload'])->name('upload');
// Workflow confirmation (after LLM classification + template generation) 

Route::post('/studio/workflow/confirm', [StudioController::class, 'confirmWorkflow'])->name('studio.workflow.confirm');

// ─── Admin ────────────────────────────────────────────────────────────────────
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/workflows',                          [AdminController::class, 'workflows'])->name('workflows');
    Route::post('/workflows/sync',                    [AdminController::class, 'syncWorkflows'])->name('workflows.sync');
    Route::patch('/workflows/{workflow}/toggle',      [AdminController::class, 'toggleWorkflow'])->name('workflows.toggle');
    Route::patch('/workflows/{workflow}/set-default', [AdminController::class, 'setDefault'])->name('workflows.set-default');
    Route::patch('/workflows/{workflow}',             [AdminController::class, 'updateWorkflow'])->name('workflows.update');
    Route::delete('/workflows/{workflow}',            [AdminController::class, 'deleteWorkflow'])->name('workflows.delete');
    Route::get('/workflows/{workflow}/preview-live', [AdminController::class, 'previewLiveWorkflow'])->name('workflows.preview-live');

    // ComfyUI direct import
    Route::get('/workflows/comfy-list',              [AdminController::class, 'listComfyWorkflows'])->name('workflows.comfy-list');
    Route::post('/workflows/comfy-import',           [AdminController::class, 'importComfyWorkflow'])->name('workflows.comfy-import');
    Route::post('/workflows/comfy-import-json',      [AdminController::class, 'importJsonDirect'])->name('workflows.comfy-import-json');
});

