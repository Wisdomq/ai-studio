<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowPlan extends Model
{
    use HasFactory;

    const STATUS_PENDING   = 'pending';
    const STATUS_QUEUED    = 'queued';    // Pre-optimized, waiting in backlog
    const STATUS_RUNNING   = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'session_id',
        'user_intent',
        'plan_steps',
        'status',
        'queue_position',
        'mood_board',
        'input_files',
    ];

    protected $casts = [
        'plan_steps'     => 'array',
        'mood_board'     => 'array',
        'input_files'    => 'array',
        'queue_position' => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowPlanStep::class, 'plan_id')->orderBy('step_order');
    }

    // ─── State helpers ────────────────────────────────────────────────────────

    public function isPending(): bool   { return $this->status === self::STATUS_PENDING; }
    public function isQueued(): bool    { return $this->status === self::STATUS_QUEUED; }
    public function isRunning(): bool   { return $this->status === self::STATUS_RUNNING; }
    public function isCompleted(): bool { return $this->status === self::STATUS_COMPLETED; }
    public function isFailed(): bool    { return $this->status === self::STATUS_FAILED; }
    public function isFinished(): bool  { return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED]); }

    public function markRunning(): void    { $this->update(['status' => self::STATUS_RUNNING]); }
    public function markCompleted(): void  { $this->update(['status' => self::STATUS_COMPLETED]); }
    public function markFailed(): void     { $this->update(['status' => self::STATUS_FAILED]); }

    public function addToQueue(): void
    {
        $nextPosition = self::where('session_id', $this->session_id)
            ->where('status', self::STATUS_QUEUED)
            ->max('queue_position') ?? 0;

        $this->update([
            'status'         => self::STATUS_QUEUED,
            'queue_position' => $nextPosition + 1,
        ]);
    }

    // ─── Status payload for frontend polling ─────────────────────────────────

    public function statusPayload(): array
    {
        $this->loadMissing('steps');

        return [
            'plan_id'        => $this->id,
            'status'         => $this->status,
            'user_intent'    => $this->user_intent,
            'queue_position' => $this->queue_position,
            'mood_board'     => $this->mood_board,
            'steps'          => $this->steps->map(fn (WorkflowPlanStep $step) => [
                'step_order'    => $step->step_order,
                'workflow_type' => $step->workflow_type,
                'purpose'       => $step->purpose,
                'status'        => $step->status,
                'output_path'   => $step->output_path,
                'output_url'    => $step->outputUrl(),
                'approved_at'   => $step->approved_at?->toISOString(),
                'error_message' => $step->error_message,
            ])->values()->all(),
        ];
    }

    // ─── Queue helpers ────────────────────────────────────────────────────────

    /**
     * Get the next queued plan for a session, ordered by queue_position.
     */
    public static function nextInQueue(string $sessionId): ?self
    {
        return self::where('session_id', $sessionId)
            ->where('status', self::STATUS_QUEUED)
            ->orderBy('queue_position')
            ->first();
    }

    /**
     * Check if any plan is currently running for this session.
     */
    public static function hasRunning(string $sessionId): bool
    {
        return self::where('session_id', $sessionId)
            ->where('status', self::STATUS_RUNNING)
            ->exists();
    }
}