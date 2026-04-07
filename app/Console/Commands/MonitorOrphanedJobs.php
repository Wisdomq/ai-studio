<?php

namespace App\Console\Commands;

use App\Jobs\ExecutePlanJob;
use App\Models\WorkflowPlan;
use App\Models\WorkflowPlanStep;
use App\Services\McpService;
use Illuminate\Console\Command;

class MonitorOrphanedJobs extends Command
{
    protected $signature = 'studio:monitor-orphaned-jobs';
    protected $description = 'Check orphaned steps and update their status when ComfyUI completes';

    public function handle(McpService $mcp): int
    {
        $orphanedSteps = WorkflowPlanStep::where('status', WorkflowPlanStep::STATUS_ORPHANED)
            ->whereNotNull('comfy_job_id')
            ->with(['plan', 'workflow'])
            ->get();

        if ($orphanedSteps->isEmpty()) {
            $this->info('No orphaned jobs to check.');
            return Command::SUCCESS;
        }

        $this->info("Checking {$orphanedSteps->count()} orphaned step(s)...");

        foreach ($orphanedSteps as $step) {
            $this->line("Checking step {$step->step_order} (ComfyUI job: {$step->comfy_job_id})...");

            try {
                $status = $mcp->checkJobStatus($step->comfy_job_id);

                if ($status['status'] === 'completed') {
                    $result = $mcp->getJobResult($step->comfy_job_id);
                    $storagePath = $result['storage_path'] ?? null;

                    if ($storagePath) {
                        $step->markAwaitingApproval($storagePath);
                        $this->info("Step {$step->step_order} completed! Marked as awaiting approval.");
                    } else {
                        $step->markFailed('ComfyUI job completed but returned no output');
                        $this->warn("Step {$step->step_order} completed but no output file.");
                    }
                } elseif ($status['status'] === 'failed') {
                    $step->markFailed('ComfyUI job failed during execution');
                    $this->warn("Step {$step->step_order} failed in ComfyUI.");
                } else {
                    $this->line("Step {$step->step_order} still {$status['status']} in ComfyUI, skipping.");
                }
            } catch (\Throwable $e) {
                $this->error("Error checking step {$step->step_order}: {$e->getMessage()}");
            }
        }

        // Check if any plans can be resumed (all steps completed)
        $this->checkPlansForResume();

        return Command::SUCCESS;
    }

    protected function checkPlansForResume(): void
    {
        $plansWithOrphans = WorkflowPlan::where('status', WorkflowPlanStep::STATUS_ORPHANED)
            ->orWhereHas('steps', fn ($q) => $q->where('status', WorkflowPlanStep::STATUS_ORPHANED))
            ->with('steps')
            ->get();

        foreach ($plansWithOrphans as $plan) {
            $hasOrphans = $plan->steps->contains(fn ($s) => $s->isOrphaned());
            $hasRunning = $plan->steps->contains(fn ($s) => $s->isRunning());

            if (!$hasOrphans && !$hasRunning) {
                $plan->markRunning();
                ExecutePlanJob::dispatch($plan->id);
                $this->info("Plan #{$plan->id} resumed - all orphaned steps resolved.");
            }
        }
    }
}