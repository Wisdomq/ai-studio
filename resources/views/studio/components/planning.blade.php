{{-- ── Phase 1: Planning ─────────────────────────────────────────────── --}}
<section id="phase-planning">
    {{-- Welcome Screen (shown when conversation is empty) --}}
    <div id="welcome-screen" class="text-center py-12 px-6 space-y-6">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-forest-500 to-forest-600 rounded-2xl shadow-lg">
            <svg class="w-9 h-9 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
            </svg>
        </div>
        
        <div>
            <h1 class="text-2xl font-bold text-gray-900 mb-2">Welcome to AI Studio</h1>
            <p class="text-gray-600 max-w-md mx-auto">Create stunning images, videos, and audio with AI. Describe what you want, and we'll bring it to life.</p>
        </div>

        {{-- Quick Action Examples --}}
        <div class="max-w-2xl mx-auto">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Try these examples</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <button onclick="useExamplePrompt('Generate an image of a futuristic city at sunset with flying cars')" 
                    class="group text-left p-4 bg-white hover:bg-forest-50 border-2 border-gray-200 hover:border-forest-300 rounded-xl transition-all">
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center shrink-0 group-hover:bg-blue-200 transition">
                            <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 mb-1">Futuristic City</p>
                            <p class="text-xs text-gray-500">Generate a sci-fi cityscape</p>
                        </div>
                    </div>
                </button>

                <button onclick="useExamplePrompt('Create a video of ocean waves crashing on a beach at golden hour')" 
                    class="group text-left p-4 bg-white hover:bg-forest-50 border-2 border-gray-200 hover:border-forest-300 rounded-xl transition-all">
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center shrink-0 group-hover:bg-purple-200 transition">
                            <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 mb-1">Ocean Waves</p>
                            <p class="text-xs text-gray-500">Create a calming beach video</p>
                        </div>
                    </div>
                </button>

                <button onclick="useExamplePrompt('Generate an image of a magical forest with glowing mushrooms and fireflies')" 
                    class="group text-left p-4 bg-white hover:bg-forest-50 border-2 border-gray-200 hover:border-forest-300 rounded-xl transition-all">
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center shrink-0 group-hover:bg-green-200 transition">
                            <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 mb-1">Magical Forest</p>
                            <p class="text-xs text-gray-500">Fantasy scene with glowing elements</p>
                        </div>
                    </div>
                </button>

                <button onclick="useExamplePrompt('Create a portrait of a cyberpunk character with neon lights')" 
                    class="group text-left p-4 bg-white hover:bg-forest-50 border-2 border-gray-200 hover:border-forest-300 rounded-xl transition-all">
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 bg-pink-100 rounded-lg flex items-center justify-center shrink-0 group-hover:bg-pink-200 transition">
                            <svg class="w-4 h-4 text-pink-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 mb-1">Cyberpunk Portrait</p>
                            <p class="text-xs text-gray-500">Character with neon aesthetics</p>
                        </div>
                    </div>
                </button>
            </div>
        </div>

        {{-- Features --}}
        <div class="flex items-center justify-center gap-6 text-xs text-gray-500 pt-4">
            <div class="flex items-center gap-1.5">
                <svg class="w-4 h-4 text-forest-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
                <span>Multi-step workflows</span>
            </div>
            <div class="flex items-center gap-1.5">
                <svg class="w-4 h-4 text-forest-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Review before generating</span>
            </div>
            <div class="flex items-center gap-1.5">
                <svg class="w-4 h-4 text-forest-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <span>Image, video & audio</span>
            </div>
        </div>
    </div>

    <div id="conversation" class="space-y-3 mb-5"></div>

    {{-- Typing Indicator --}}
    <div id="typing-indicator" class="hidden slide-in">
        <div class="flex items-start gap-3 p-4 bg-gray-50 border border-gray-200 rounded-2xl">
            <div class="w-8 h-8 bg-gradient-to-br from-forest-500 to-forest-600 rounded-full flex items-center justify-center shrink-0">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
            </svg>
            </div>
            <div class="flex-1 flex items-center gap-1 pt-2">
                <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms"></div>
                <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms"></div>
                <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms"></div>
            </div>
        </div>
    </div>

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