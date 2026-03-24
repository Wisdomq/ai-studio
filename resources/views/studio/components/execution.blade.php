{{-- ── Phase 3: Execution ────────────────────────────────────────────── --}}
<section id="phase-execution" class="hidden space-y-4">
    <div class="flex items-center gap-2">
        <div class="h-px flex-1 bg-forest-100"></div>
        <span class="text-xs font-semibold text-forest-600 uppercase tracking-widest px-2">Generating</span>
        <div class="h-px flex-1 bg-forest-100"></div>
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