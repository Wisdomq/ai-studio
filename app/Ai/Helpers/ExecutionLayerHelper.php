<?php

namespace App\Ai\Helpers;

trait ExecutionLayerHelper
{
    /**
     * Compute the execution layer for a single step given already-computed
     * layers for all prior steps.
     *
     * @param array $step          The step array (must include depends_on)
     * @param array $layerByOrder  Map of step_order => execution_layer for all prior steps
     * @return int
     */
    protected function computeExecutionLayer(array $step, array $layerByOrder): int
    {
        $deps = $step['depends_on'] ?? [];

        if (empty($deps)) {
            return 0;
        }

        $maxDepLayer = 0;
        foreach ($deps as $key => $value) {
            // keyed format: $key = type string, $value = step_order
            // flat format:  $key = array index,  $value = step_order
            $depStepOrder = is_string($key) ? (int) $value : (int) $value;
            $depLayer     = $layerByOrder[$depStepOrder] ?? 0;
            $maxDepLayer  = max($maxDepLayer, $depLayer);
        }

        return $maxDepLayer + 1;
    }

    /**
     * Recompute execution layers for all steps in a plan.
     * Modifies the steps array in place.
     *
     * @param array $steps Array of step arrays (must be ordered by step_order)
     * @return void
     */
    protected function recomputeExecutionLayers(array &$steps): void
    {
        $layerByOrder = [];

        foreach ($steps as &$step) {
            $stepOrder = $step['step_order'] ?? 0;
            $layer = $this->computeExecutionLayer($step, $layerByOrder);
            $step['execution_layer'] = $layer;
            $layerByOrder[$stepOrder] = $layer;
        }
    }
}
