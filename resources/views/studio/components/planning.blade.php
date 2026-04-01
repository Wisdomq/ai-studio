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
        <div id="workflow-preview" class="hidden bg-white border rounded-2xl p-4">
            <div class="flex justify-between items-center mb-2">
                <h3 class="text-sm font-semibold">Generated Workflow</h3>
                <button id="btn-save-workflow">Save</button>
            </div>
            <textarea id="workflow-json"
                class="w-full h-64 text-xs font-mono border rounded p-2"></textarea>
        </div>
        
    </div>

    {{-- Input --}}
    <div id="input-area" class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden focus-within:border-forest-400 focus-within:shadow-md transition-all duration-200">
        <div id="attached-files" class="hidden px-5 pt-3 pb-0"></div>
        <textarea id="user-input" rows="2"
            placeholder="Describe what you want to create..."
            class="w-full px-5 pt-4 pb-2 text-sm resize-none bg-transparent border-none focus:ring-0 text-gray-800 placeholder-gray-400"></textarea>
        <div class="flex items-center justify-between px-4 pb-3">
            <div class="flex items-center gap-3">
                <label for="file-input" class="cursor-pointer p-1.5 text-gray-400 hover:text-forest-600 hover:bg-forest-50 rounded-lg transition" title="Attach file">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                    </svg>
                </label>
                <input type="file" id="file-input" class="hidden" accept="image/*,video/*,audio/*" multiple>
                <span class="text-xs text-gray-400">Enter to send · Shift+Enter for new line</span>
            </div>
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