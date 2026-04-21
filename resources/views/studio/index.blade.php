@extends('layouts.studio')

@section('content')

{{-- Include components --}}
@include('studio.components.jobs_panel')
@include('studio.components.mood_board')

{{-- ── Workflows Panel ──────────────────────────────────────────────────────── --}}
{{-- Backdrop --}}
<div id="workflows-panel-overlay"
    class="hidden fixed inset-0 bg-black/20 z-40 backdrop-blur-sm"
    onclick="toggleWorkflowsPanel(false)"></div>

{{-- Slide-out panel --}}
<aside id="workflows-panel"
    class="fixed top-0 left-0 h-full w-80 bg-white border-r border-gray-200 shadow-xl z-50 flex flex-col"
    style="transform: translateX(-100%); transition: transform 0.25s ease;">

    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
        <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-forest-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
            </svg>
            <span class="text-sm font-semibold text-gray-900">Available Workflows</span>
        </div>
        <button onclick="toggleWorkflowsPanel(false)"
            class="p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <div class="px-4 py-3 border-b border-gray-100 bg-forest-50">
        <p class="text-xs text-forest-700">Reference these IDs when building multi-step generation plans.</p>
    </div>

    <div id="workflows-panel-list" class="flex-1 overflow-y-auto px-4 py-3 space-y-2">
        {{-- Populated by renderWorkflowsPanel() in JS --}}
    </div>
</aside>

<div id="studio-app" class="min-h-screen flex flex-col">

    {{-- ── Header ───────────────────────────────────────────────────────────── --}}
    <x-studio-navbar 
        currentPage="studio"
        :showComfyStatus="true"
        :showPhaseIndicator="true"
        :showWorkflowsButton="true"
        :showMoodBoardButton="true"
        :showJobsButton="true"
    />

    {{-- Top progress bar --}}
    <div id="top-progress" class="hidden h-0.5 bg-forest-100">
        <div class="progress-bar w-full"></div>
    </div>

    {{-- ── Main ─────────────────────────────────────────────────────────────── --}}
    <main class="flex-1 max-w-2xl mx-auto w-full px-4 py-8 space-y-6">
        @include('studio.components.planning')
        @include('studio.components.refinement')
        @include('studio.components.execution')
    </main>
</div>

{{-- ── Step execution template ──────────────────────────────────────────── --}}
<template id="tpl-exec-step">
    <div class="exec-step bg-white border-2 rounded-2xl overflow-hidden shadow-sm transition-all hover:shadow-md">
        <div class="px-5 py-4 flex items-center justify-between">
            <div class="flex-1 min-w-0">
                <span class="step-name text-sm font-medium text-gray-800 block"></span>
                <div class="step-dependencies text-xs text-gray-500 mt-1 hidden"></div>
            </div>
            <span class="step-badge text-xs px-2.5 py-1 rounded-full font-medium"></span>
        </div>
        {{-- Cancel button (shown only when running) --}}
        <button class="btn-cancel-step hidden items-center gap-1.5 px-4 py-2 mx-5 mb-3 bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 rounded-lg text-xs font-medium transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
            Cancel Generation
        </button>
        <div class="step-cancelled hidden border-t border-gray-100 bg-gray-50 px-5 py-3">
            <p class="text-xs text-gray-600">This step was cancelled.</p>
        </div>
        <div class="step-result hidden border-t border-gray-100">
            <div class="step-media-wrap bg-gray-50"></div>
            <div class="px-5 py-4 flex gap-3">
                <button class="btn-approve-step flex-1 py-2.5 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-medium transition flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    Approve
                </button>
                <button class="btn-reject-step flex-1 py-2.5 bg-white hover:bg-red-50 text-red-600 border border-red-200 rounded-xl text-sm font-medium transition">
                    Reject & Redo
                </button>
            </div>
        </div>
        <div class="step-error hidden border-t border-red-100 bg-red-50 px-5 py-4">
            <div class="flex items-start gap-2">
                <svg class="w-4 h-4 text-red-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div class="flex-1 min-w-0">
                    <p class="text-xs font-semibold text-red-700 mb-1">Generation failed</p>
                    <p class="step-error-text text-xs text-red-600 font-mono leading-relaxed break-all"></p>
                </div>
            </div>
            <button class="btn-retry-step mt-3 w-full py-2 bg-white hover:bg-red-50 text-red-600 border border-red-200 rounded-xl text-xs font-medium transition flex items-center justify-center gap-2">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Retry Generation
            </button>
        </div>
    </div>
</template>
<script>
// ── Route config (Blade-rendered, available to all studio JS modules) ─────────
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

const STUDIO_ROUTES = {
    index:          '{{ route("studio.index") }}',
    planner:        '{{ route("studio.planner") }}',
    planApprove:    '{{ route("studio.plan.approve") }}',
    planBase:       '/studio/plan',
    refineStep:     '{{ route("studio.plan.refine-step") }}',
    upload:         '/studio/upload',
    jobs:           '{{ route("studio.jobs") }}',
    queueStatus:    '{{ route("studio.queue-status") }}',
    runQueue:       '{{ route("studio.queue.run-next") }}',
    workflowConfirm:'{{ route("studio.workflow.confirm") }}',
};

// Active workflows injected from PHP — used by the workflows panel and
// referenced by the planner to show IDs alongside workflow names.
const STUDIO_WORKFLOWS = @json($workflows ?? []);

// ── ComfyUI Status LED ─────────────────────────────────────────────────────────
(function() {
    const led = document.getElementById('comfy-led');
    const label = document.getElementById('comfy-label');
    const container = document.getElementById('comfy-status');

    function updateStatus(online) {
        if (online) {
            led.className = 'w-2.5 h-2.5 rounded-full bg-blue-500 shadow-sm shadow-blue-500/50';
            label.className = 'text-xs text-blue-600';
            label.textContent = 'ComfyUI';
            container.title = 'ComfyUI: Online';
        } else {
            led.className = 'w-2.5 h-2.5 rounded-full bg-red-500 shadow-sm shadow-red-500/50';
            label.className = 'text-xs text-red-500';
            label.textContent = 'ComfyUI';
            container.title = 'ComfyUI: Offline';
        }
    }

    async function checkComfyStatus() {
        try {
            const resp = await fetch('{{ route("studio.comfy-health") }}');
            const data = await resp.json();
            updateStatus(data.reachable === true);
        } catch {
            updateStatus(false);
        }
    }

    // Check on load
    checkComfyStatus();

    // Re-check every 30 seconds
    setInterval(checkComfyStatus, 30000);
})();
</script>

<script src="{{ asset('js/studio/studio.js') }}" defer></script>
<script src="{{ asset('js/studio/studio.planning.js') }}" defer></script>
<script src="{{ asset('js/studio/studio.refinement.js') }}" defer></script>
<script src="{{ asset('js/studio/studio.execution.js') }}" defer></script>
<script src="{{ asset('js/studio/studio.panels.js') }}" defer></script>

@endsection