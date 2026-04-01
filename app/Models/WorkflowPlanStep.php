<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class WorkflowPlanStep extends Model
{
    use HasFactory;

    // ─── V2 Status machine ────────────────────────────────────────────────────
    // pending → running → awaiting_approval → completed
    //                  ↘ pending (on user reject, back to refinement)
    // running → failed
    const STATUS_PENDING            = 'pending';
    const STATUS_RUNNING            = 'running';
    const STATUS_AWAITING_APPROVAL  = 'awaiting_approval';
    const STATUS_COMPLETED          = 'completed';
    const STATUS_FAILED             = 'failed';

    protected $fillable = [
        'plan_id',
        'workflow_id',
        'step_order',
        'workflow_type',
        'purpose',
        'refined_prompt',
        'depends_on',
        'input_file_path',
        'comfy_job_id',
        'status',
        'output_path',
        'approved_at',
        'error_message',
    ];

    protected $casts = [
        'depends_on'  => 'array',
        'approved_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WorkflowPlan::class, 'plan_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    // ─── State helpers ────────────────────────────────────────────────────────

    public function isPending(): bool           { return $this->status === self::STATUS_PENDING; }
    public function isRunning(): bool           { return $this->status === self::STATUS_RUNNING; }
    public function isAwaitingApproval(): bool  { return $this->status === self::STATUS_AWAITING_APPROVAL; }
    public function isCompleted(): bool         { return $this->status === self::STATUS_COMPLETED; }
    public function isFailed(): bool            { return $this->status === self::STATUS_FAILED; }
    public function isApproved(): bool          { return $this->approved_at !== null; }

    public function markRunning(): void
    {
        $this->update(['status' => self::STATUS_RUNNING]);
    }

    public function markAwaitingApproval(string $outputPath): void
    {
        $this->update([
            'status'      => self::STATUS_AWAITING_APPROVAL,
            'output_path' => $outputPath,
        ]);
    }

    public function markCompleted(): void
    {
        $this->update([
            'status'      => self::STATUS_COMPLETED,
            'approved_at' => now(),
        ]);
    }

    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status'        => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Reset step back to pending for re-refinement after user rejection.
     * Clears output so the UI knows there's nothing to show.
     */
    public function resetForRefinement(): void
    {
        $this->update([
            'status'        => self::STATUS_PENDING,
            'output_path'   => null,
            'comfy_job_id'  => null,
            'approved_at'   => null,
            'error_message' => null,
        ]);
    }

    // ─── Readiness check ─────────────────────────────────────────────────────

    /**
     * A step is ready to execute when:
     *   1. It has a refined prompt confirmed by the user
     *   2. All steps it depends_on are completed (and approved)
     */
    public function isReady(WorkflowPlan $plan): bool
    {
        if (empty($this->refined_prompt)) {
            return false;
        }

        foreach ($this->depends_on ?? [] as $dependsOnOrder) {
            $dep = $plan->steps->firstWhere('step_order', $dependsOnOrder);
            if (! $dep || ! $dep->isCompleted()) {
                return false;
            }
        }

        return true;
    }

    // ─── Output URL ──────────────────────────────────────────────────────────

    /**
     * Returns a public URL for the output file, or null if not yet generated.
     * output_path is always storage-relative (e.g. comfyui-outputs/foo.png).
     */
    public function outputUrl(): ?string
    {
        if (! $this->output_path) {
            return null;
        }

        return Storage::disk('public')->url($this->output_path);
    }

    /**
     * Returns direct asset URL bypassing storage disk URL generation.
     * Use this if Storage::url() isn't working (e.g. missing symlink).
     */
    public function assetUrl(): ?string
    {
        if (! $this->output_path) {
            return null;
        }

        $baseUrl = rtrim(config('app.url', 'http://localhost'), '/');
        return $baseUrl . '/storage/' . ltrim($this->output_path, '/');
    }
}