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
    const STATUS_CANCELLED          = 'cancelled';
    const STATUS_ORPHANED           = 'orphaned';

    protected $fillable = [
        'plan_id',
        'workflow_id',
        'step_order',
        'execution_layer',   // DAG layer — 0 = independent, N = depends on layer N-1
        'workflow_type',
        'purpose',
        'refined_prompt',
        'depends_on',
        'input_file_path',
        'input_files',       // JSON map: {"image": "path", "audio": "path"} — multi-input workflows
        'comfy_job_id',
        'mcp_asset_id',      // MCP server asset_id — secondary reference for regenerate/provenance
        'status',
        'output_path',
        'approved_at',
        'error_message',
    ];

    protected $casts = [
        'depends_on'      => 'array',
        'input_files'     => 'array',
        'approved_at'     => 'datetime',
        'execution_layer' => 'integer',
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
    public function isCancelled(): bool         { return $this->status === self::STATUS_CANCELLED; }
    public function isOrphaned(): bool           { return $this->status === self::STATUS_ORPHANED; }
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

    public function markCancelled(): void
    {
        $this->update([
            'status'        => self::STATUS_CANCELLED,
            'error_message' => 'Cancelled by user.',
        ]);
    }

    public function markOrphaned(): void
    {
        $this->update([
            'status'        => self::STATUS_ORPHANED,
            'error_message' => 'Job timed out but is still running in ComfyUI. Check back later.',
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
            'mcp_asset_id'  => null,
            'approved_at'   => null,
            'error_message' => null,
        ]);
    }

    // ─── Readiness check ─────────────────────────────────────────────────────

    /**
     * A step is ready to execute when:
     *   1. It has a refined prompt confirmed by the user
     *   2. All steps it depends_on are completed (and approved)
     *   3. For input types NOT covered by a dependency step's output_type, a
     *      file must be present in input_files (or the legacy input_file_path).
     *      Types supplied at runtime by collectDependencyFiles() from a completed
     *      dependency's output_path are excluded from this check — requiring them
     *      here would force a spurious upload prompt for auto-chained steps.
     *
     * supports keyed depends_on format: ['image' => 0, 'video' => 1]
     * where keys are input types and values are step orders.
     */
    public function isReady(WorkflowPlan $plan): bool
    {
        if (empty($this->refined_prompt)) {
            return false;
        }

        // Handle keyed depends_on format: [type => stepOrder]
        // Also support legacy flat format: [stepOrder, stepOrder]
        $depCoveredTypes = [];
        foreach ($this->depends_on ?? [] as $key => $value) {
            $stepOrder = is_string($key) ? $value : $value; // keyed or flat
            $type = is_string($key) ? $key : null; // only set for keyed format

            $dep = $plan->steps->firstWhere('step_order', $stepOrder);
            if (! $dep || ! $dep->isCompleted()) {
                return false;
            }

            // Track covered types for the file presence check below
            if ($type) {
                $depCoveredTypes[] = $type;
            } else {
                // For flat format, use the dependency's output_type
                if ($dep->relationLoaded('workflow') && $dep->workflow) {
                    $outputType = $dep->workflow->output_type ?? null;
                    if ($outputType) {
                        $depCoveredTypes[] = $outputType;
                    }
                }
            }
        }

        // Only check file presence for types NOT already covered by a dependency.
        $requiredTypes  = $this->workflow->input_types ?? [];
        $uncoveredTypes = array_diff($requiredTypes, $depCoveredTypes);

        if (! empty($uncoveredTypes)) {
            $available = array_keys($this->input_files ?? []);

            // Also accept the legacy single-path column
            if (! empty($this->input_file_path)) {
                $ext    = strtolower(pathinfo($this->input_file_path, PATHINFO_EXTENSION));
                $legacy = match (true) {
                    in_array($ext, ['jpg','jpeg','png','webp','gif','bmp','tiff']) => 'image',
                    in_array($ext, ['mp4','webm','mov','avi','mkv'])               => 'video',
                    in_array($ext, ['mp3','wav','ogg','flac','aac','m4a'])         => 'audio',
                    default                                                                 => null,
                };
                if ($legacy && ! in_array($legacy, $available)) {
                    $available[] = $legacy;
                }
            }

            foreach ($uncoveredTypes as $type) {
                if (! in_array($type, $available)) {
                    return false;
                }
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