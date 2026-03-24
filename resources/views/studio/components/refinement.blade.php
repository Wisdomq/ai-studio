{{-- ── Phase 2: Prompt Refinement ───────────────────────────────────── --}}
<section id="phase-refinement" class="hidden space-y-4">
    <div class="flex items-center gap-2">
        <div class="h-px flex-1 bg-forest-100"></div>
        <span class="text-xs font-semibold text-forest-600 uppercase tracking-widest px-2">Refine Prompts</span>
        <div class="h-px flex-1 bg-forest-100"></div>
    </div>
    <div id="refinement-steps" class="space-y-4"></div>

    {{-- Dispatch buttons --}}
    <div id="dispatch-actions" class="hidden space-y-3">
        <button id="btn-dispatch"
            class="w-full py-3.5 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-semibold transition shadow-sm flex items-center justify-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
            Generate Now
        </button>
        <button id="btn-add-to-queue"
            class="w-full py-3 bg-white hover:bg-forest-50 text-forest-700 border border-forest-300 rounded-xl text-sm font-medium transition flex items-center justify-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add to Queue & Continue Creating
        </button>
        <button id="btn-export-prompts" onclick="exportPrompts()"
            class="w-full py-2.5 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-700 border border-gray-200 rounded-xl text-xs font-medium transition flex items-center justify-center gap-2">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Export All Prompts
        </button>
    </div>
</section>