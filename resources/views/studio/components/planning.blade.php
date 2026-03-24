{{-- ── Phase 1: Planning ─────────────────────────────────────────────── --}}
<section id="phase-planning">
    <div id="conversation" class="space-y-3 mb-5"></div>

    {{-- Disambiguation card (injected by JS) --}}

    {{-- Plan card --}}
    <div id="plan-card" class="hidden slide-in bg-white border border-forest-200 rounded-2xl overflow-hidden shadow-sm">
        <div class="bg-forest-50 border-b border-forest-100 px-5 py-3 flex items-center gap-2">
            <svg class="w-4 h-4 text-forest-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <span class="text-sm font-semibold text-forest-700">Generation Plan</span>
        </div>
        <div id="plan-steps-list" class="px-5 py-4 space-y-3"></div>
        <div class="px-5 pb-5 flex gap-3">
            <button id="btn-approve-plan"
                class="px-5 py-2.5 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-medium transition shadow-sm">
                ✓ Approve Plan
            </button>
            <button id="btn-reject-plan"
                class="px-5 py-2.5 bg-white hover:bg-gray-50 text-gray-600 border border-gray-200 rounded-xl text-sm font-medium transition">
                Start Over
            </button>
        </div>
    </div>

    {{-- Input --}}
    <div id="input-area" class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden focus-within:border-forest-400 focus-within:shadow-md transition-all duration-200">
        <textarea id="user-input" rows="2"
            placeholder="Describe what you want to create..."
            class="w-full px-5 pt-4 pb-2 text-sm resize-none bg-transparent border-none focus:ring-0 text-gray-800 placeholder-gray-400"></textarea>
        <div class="flex items-center justify-between px-4 pb-3">
            <span class="text-xs text-gray-400">Enter to send · Shift+Enter for new line</span>
            <button id="btn-send"
                class="px-5 py-2 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-medium transition shadow-sm flex items-center gap-2">
                <span>Send</span>
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                </svg>
            </button>
        </div>
    </div>
</section>