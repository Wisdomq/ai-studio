{{-- ── Phase 3: Execution ────────────────────────────────────────────── --}}
<section id="phase-execution" class="hidden space-y-4">
    <div class="flex items-center gap-2">
        <div class="h-px flex-1 bg-forest-100"></div>
        <span class="text-xs font-semibold text-forest-600 uppercase tracking-widest px-2">Generating</span>
        <div class="h-px flex-1 bg-forest-100"></div>
    </div>
    
    {{-- Progress Bar --}}
    <div id="workflow-progress" class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-medium text-gray-700">Workflow Progress</span>
            <span id="progress-text" class="text-sm text-gray-500">0 / 0 steps</span>
        </div>
        <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
            <div id="progress-bar" class="bg-gradient-to-r from-forest-500 to-forest-600 h-3 rounded-full progress-bar-animated shadow-sm" style="width: 0%"></div>
        </div>
        <div class="flex items-center justify-between mt-2 text-xs text-gray-500">
            <span id="progress-status">Preparing...</span>
            <span id="progress-percentage">0%</span>
        </div>
    </div>
    
    <div id="orphaned-notice" class="hidden bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 flex items-center gap-3">
        <svg class="w-5 h-5 text-blue-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <div>
            <p class="text-sm font-medium text-blue-700">Some jobs are still running</p>
            <p class="text-xs text-blue-600 mt-0.5">The generation is taking longer than expected. We'll notify you when it's done.</p>
        </div>
    </div>
    <div id="execution-steps" class="space-y-4"></div>

    {{-- Continue creating button (shown after dispatch) --}}
    <button id="btn-continue-creating" onclick="resetToPlanning()"
        class="hidden w-full py-3 bg-white hover:bg-forest-50 text-forest-700 border border-forest-200 rounded-xl text-sm font-medium transition flex items-center justify-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Start a New Generation
    </button>
</section>