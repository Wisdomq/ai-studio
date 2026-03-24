<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\StudioController;
use Illuminate\Support\Facades\Route;

// ─── Root ─────────────────────────────────────────────────────────────────────
Route::get('/', fn () => redirect()->route('studio.index'));

// ─── Studio Pipeline ──────────────────────────────────────────────────────────
Route::prefix('studio')->name('studio.')->group(function () {

    // Views
    Route::get('/',             [StudioController::class, 'index'])->name('index');
    Route::get('/generations',  [StudioController::class, 'generations'])->name('generations');
    Route::get('/plan/{plan}/result', [StudioController::class, 'result'])->name('result');

    // Phase 1 — Planning
    Route::post('/planner',      [StudioController::class, 'planner'])->name('planner');
    Route::post('/plan/approve', [StudioController::class, 'approvePlan'])->name('plan.approve');

    // Phase 2 — Prompt Refinement
    Route::post('/plan/refine-step',                    [StudioController::class, 'refineStep'])->name('plan.refine-step');
    Route::post('/plan/{plan}/step/{order}/confirm',    [StudioController::class, 'confirmStep'])->name('plan.step.confirm');

    // Phase 3 — Execution
    Route::post('/plan/{plan}/dispatch',                [StudioController::class, 'dispatch'])->name('plan.dispatch');
    Route::post('/plan/{plan}/queue',                   [StudioController::class, 'queuePlan'])->name('plan.queue');
    Route::post('/plan/{plan}/dispatch-from-queue',     [StudioController::class, 'dispatchFromQueue'])->name('plan.dispatch-from-queue');
    Route::get('/plan/{plan}/status',                   [StudioController::class, 'status'])->name('plan.status');
    Route::post('/plan/{plan}/step/{order}/approve',    [StudioController::class, 'approveStep'])->name('plan.step.approve');
    Route::post('/plan/{plan}/step/{order}/reject',     [StudioController::class, 'rejectStep'])->name('plan.step.reject');

    // Jobs panel — all session jobs
    Route::get('/jobs',                                 [StudioController::class, 'jobs'])->name('jobs');
    Route::get('/queue-status',                         [StudioController::class, 'queueStatus'])->name('queue-status');
    Route::post('/queue/run-next',                      [StudioController::class, 'runNextInQueue'])->name('queue.run-next');
    Route::post('/plan/{plan}/mood-board',              [StudioController::class, 'saveMoodBoard'])->name('plan.mood-board');

    // File upload
    Route::post('/upload', [StudioController::class, 'upload'])->name('upload');
});

// ─── Admin ────────────────────────────────────────────────────────────────────
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/workflows',                          [AdminController::class, 'workflows'])->name('workflows');
    Route::post('/workflows/sync',                    [AdminController::class, 'syncWorkflows'])->name('workflows.sync');
    Route::patch('/workflows/{workflow}/toggle',      [AdminController::class, 'toggleWorkflow'])->name('workflows.toggle');
    Route::patch('/workflows/{workflow}/set-default', [AdminController::class, 'setDefault'])->name('workflows.set-default');
    Route::patch('/workflows/{workflow}',             [AdminController::class, 'updateWorkflow'])->name('workflows.update');
    Route::delete('/workflows/{workflow}',            [AdminController::class, 'deleteWorkflow'])->name('workflows.delete');

    // ComfyUI direct import
    Route::get('/workflows/comfy-list',              [AdminController::class, 'listComfyWorkflows'])->name('workflows.comfy-list');
    Route::post('/workflows/comfy-import',           [AdminController::class, 'importComfyWorkflow'])->name('workflows.comfy-import');
    Route::post('/workflows/comfy-import-json',      [AdminController::class, 'importJsonDirect'])->name('workflows.comfy-import-json');
});