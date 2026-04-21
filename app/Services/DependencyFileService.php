<?php

namespace App\Services;

use App\Models\WorkflowPlan;
use App\Models\WorkflowPlanStep;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * DependencyFileService - Shared logic for collecting input files
 * 
 * Extracts dependency file collection logic from ExecutePlanJob and SubmitStepJob
 * to avoid duplication and ensure consistent behavior.
 */
class DependencyFileService
{
    /**
     * Collect all input files for a step from three sources:
     *   1. Step-level upload  — $step->input_file_path (set via refinement phase)
     *   2. Plan-level uploads — $plan->input_files[] (attached during plan creation)
     *   3. Dependency outputs — prior steps that this step depends on
     *
     * Returns map of media_type => storage-relative path.
     * Files are NOT uploaded here — the caller handles ComfyUI upload.
     *
     * @return array<string, string>  e.g. ['image' => 'comfyui-inputs/foo.png', 'video' => '...']
     */
    public function collectDependencyFiles(WorkflowPlanStep $step, WorkflowPlan $plan): array
    {
        $files = [];

        // ── 1a. Step-level multi-input map (new column — preferred) ────────────
        // {"image": "comfyui-inputs/a.png", "audio": "comfyui-inputs/b.mp3"}
        foreach ($step->input_files ?? [] as $mediaType => $path) {
            if (Storage::disk('public')->exists($path)) {
                $files[$mediaType] = $path;
                Log::info("DependencyFileService: Step-level {$mediaType} input (input_files) → {$path}");
            } else {
                throw new \RuntimeException("Step input file missing [{$mediaType}]: {$path}");
            }
        }

        // ── 1b. Legacy single-file column — only if input_files didn't already
        //        supply a file for this media type (backward compat, not overwritten)
        if (empty($files) && ! empty($step->input_file_path)) {
            $path = $step->input_file_path;
            if (Storage::disk('public')->exists($path)) {
                $mediaType        = $this->mediaTypeFromPath($path);
                $files[$mediaType] = $path;
                Log::info("DependencyFileService: Step-level input (legacy input_file_path) → {$path}");
            } else {
                throw new \RuntimeException("Step input file missing: {$path}");
            }
        }

        // ── 2. Plan-level uploads (attached during plan approval) ───────────────
        $planInputFiles     = $plan->input_files ?? [];
        $workflowInputTypes = $step->workflow->input_types ?? [];

        foreach ($planInputFiles as $file) {
            $mediaType   = $file['media_type'] ?? null;
            $storagePath = $file['storage_path'] ?? null;

            if (! $mediaType || ! $storagePath) {
                continue;
            }

            if (! in_array($mediaType, $workflowInputTypes, true)) {
                continue;
            }

            if (isset($files[$mediaType])) {
                continue; // Step-level upload takes priority
            }

            if (Storage::disk('public')->exists($storagePath)) {
                $files[$mediaType] = $storagePath;
                Log::info("DependencyFileService: Plan-level {$mediaType} input → {$storagePath}");
            } else {
                throw new \RuntimeException("Plan input file missing: {$storagePath}");
            }
        }

        // ── 3. Dependency outputs from prior steps ─────────────────────────────
        foreach ($step->depends_on ?? [] as $key => $value) {
            $stepOrder  = is_string($key) ? $value : $key;
            $neededType = is_string($key) ? $key : null;

            $dep = $plan->steps->firstWhere('step_order', $stepOrder);

            if (! $dep) {
                throw new \RuntimeException("Dependency step {$stepOrder} not found for step {$step->step_order}");
            }

            if (! $dep->output_path) {
                throw new \RuntimeException(
                    "Dependency step {$stepOrder} has no output. Cannot execute step {$step->step_order}."
                );
            }

            if (! Storage::disk('public')->exists($dep->output_path)) {
                throw new \RuntimeException("Dependency output file missing: {$dep->output_path}");
            }

            $fileType = $neededType ?? ($dep->workflow->output_type ?? null);

            if (! $fileType) {
                continue;
            }

            if (isset($files[$fileType])) {
                continue;
            }

            $files[$fileType] = $dep->output_path;
            Log::info("DependencyFileService: Dependency step {$stepOrder} output → {$fileType}: {$dep->output_path}");
        }

        // ── 4. Safety-net: scan all prior completed steps for still-uncovered types ──
        //
        // Catches two scenarios:
        //   a) depends_on map has a gap (e.g. LLM omitted a workflow from the READY signal
        //      so no step in the plan produces the needed type via explicit dependency).
        //   b) A step in the same execution layer happens to produce a needed type that
        //      was not wired explicitly — the layer system guarantees it completed first.
        //
        // Scans backwards (latest-match-wins) to be consistent with the planner.
        $requiredTypes = $step->workflow->input_types ?? [];

        if (! empty($requiredTypes)) {
            $uncoveredTypes = array_diff($requiredTypes, array_keys($files));

            if (! empty($uncoveredTypes)) {
                $priorSteps = $plan->steps
                    ->filter(fn (WorkflowPlanStep $s) =>
                        $s->step_order < $step->step_order
                        && $s->isCompleted()
                        && ! empty($s->output_path)
                        && Storage::disk('public')->exists($s->output_path)
                    )
                    ->sortByDesc('step_order')
                    ->values();

                foreach ($uncoveredTypes as $neededType) {
                    foreach ($priorSteps as $prior) {
                        $priorType = $prior->workflow->output_type
                            ?? $this->mediaTypeFromPath($prior->output_path);

                        if ($priorType !== $neededType) {
                            continue;
                        }

                        $files[$neededType] = $prior->output_path;
                        Log::info("DependencyFileService: Safety-net resolved {$neededType} from step {$prior->step_order} → {$prior->output_path}");
                        break;
                    }
                }
            }
        }

        return $files;
    }

    /**
     * Infer media type from a file path extension.
     */
    protected function mediaTypeFromPath(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match (true) {
            in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tiff']) => 'image',
            in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv'])                 => 'video',
            in_array($ext, ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a'])          => 'audio',
            default                                                                   => 'image',
        };
    }
}
