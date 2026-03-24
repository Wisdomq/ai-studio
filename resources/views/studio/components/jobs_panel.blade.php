{{-- ── Floating Jobs Panel ───────────────────────────────────────────── --}}
<div id="jobs-panel-overlay" class="hidden fixed inset-0 z-40" onclick="toggleJobsPanel(false)"></div>

<div id="jobs-panel"
     class="hidden-panel fixed top-0 right-0 h-full w-96 max-w-full bg-white border-l border-gray-200 shadow-2xl z-50 flex flex-col"
     style="transform:translateX(100%)">

    {{-- Panel header --}}
    <div class="bg-white border-b border-gray-100 px-5 py-4 flex items-center justify-between shrink-0">
        <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-forest-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
            </svg>
            <span class="font-semibold text-gray-900 text-sm">Jobs</span>
            <span id="panel-badge" class="hidden text-xs bg-forest-500 text-white px-1.5 py-0.5 rounded-full font-medium"></span>
        </div>
        <div class="flex items-center gap-2">
            <button id="btn-run-queue"
                onclick="runNextInQueue()"
                class="hidden text-xs px-3 py-1.5 bg-forest-500 hover:bg-forest-600 text-white rounded-lg font-medium transition">
                ▶ Run Queue
            </button>
            <button onclick="toggleJobsPanel(false)"
                class="text-gray-400 hover:text-gray-600 transition p-1 rounded-lg hover:bg-gray-100">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </div>

    {{-- Queue summary bar --}}
    <div id="queue-summary" class="hidden bg-forest-50 border-b border-forest-100 px-5 py-2.5 flex items-center gap-4 text-xs">
        <span id="qs-running" class="text-forest-700 font-medium"></span>
        <span id="qs-queued" class="text-amber-700 font-medium"></span>
        <span id="qs-awaiting" class="text-blue-700 font-medium"></span>
    </div>

    {{-- Jobs list --}}
    <div id="jobs-list" class="flex-1 overflow-y-auto p-4 space-y-3">
        <div class="text-center text-gray-400 text-sm py-8">
            <svg class="w-8 h-8 mx-auto mb-2 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
            No jobs yet
        </div>
    </div>
</div>