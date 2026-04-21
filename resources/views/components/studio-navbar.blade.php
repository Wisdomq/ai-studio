@props([
    'currentPage' => 'studio',
    'showComfyStatus' => false,
    'showPhaseIndicator' => false,
    'showWorkflowsButton' => false,
    'showMoodBoardButton' => false,
    'showJobsButton' => false,
    'customActions' => null,
])

<header class="bg-white border-b border-gray-200 sticky top-0 z-50 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6">
        <div class="flex items-center justify-between h-16">
            
            {{-- Left: Logo & Brand --}}
            <div class="flex items-center gap-6">
                <a href="{{ route('studio.index') }}" class="flex items-center gap-3 hover:opacity-80 transition group">
                    <div class="w-9 h-9 bg-gradient-to-br from-forest-500 to-forest-600 rounded-xl flex items-center justify-center shadow-sm group-hover:shadow-md transition">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                        </svg>
                    </div>
                    <div class="hidden sm:block">
                        <span class="text-lg font-bold text-gray-900 tracking-tight">AI Studio</span>
                        <div class="text-[10px] text-gray-400 font-medium -mt-0.5">Creative Generation</div>
                    </div>
                </a>

                {{-- Navigation Links (Desktop) --}}
                <nav class="hidden md:flex items-center gap-1">
                    <a href="{{ route('studio.index') }}" 
                       class="nav-link {{ $currentPage === 'studio' ? 'active' : '' }} px-3 py-2 rounded-lg text-sm font-medium transition flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Create
                    </a>
                    <a href="{{ route('studio.generations') }}" 
                       class="nav-link {{ $currentPage === 'generations' ? 'active' : '' }} px-3 py-2 rounded-lg text-sm font-medium transition flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        Gallery
                    </a>
                </nav>
            </div>

            {{-- Center: Status & Phase Indicators --}}
            <div class="hidden lg:flex items-center gap-3">
                @if($showComfyStatus)
                    <div id="comfy-status" class="flex items-center gap-2 px-3 py-1.5 bg-gray-50 rounded-lg" title="Checking ComfyUI...">
                        <span id="comfy-led" class="w-2 h-2 rounded-full bg-gray-300 transition-colors duration-300"></span>
                        <span id="comfy-label" class="text-xs font-medium text-gray-500">ComfyUI</span>
                    </div>
                @endif

                @if($showPhaseIndicator)
                    <div id="phase-indicator" class="text-xs font-semibold text-forest-700 bg-forest-50 border border-forest-200 px-3 py-1.5 rounded-lg">
                        Ready to create
                    </div>
                @endif
            </div>

            {{-- Right: Actions & Tools --}}
            <div class="flex items-center gap-2">
                
                {{-- Custom Actions Slot --}}
                @if($customActions)
                    {!! $customActions !!}
                @endif
                
                {{-- Workflows Button --}}
                @if($showWorkflowsButton)
                    <button onclick="toggleWorkflowsPanel()"
                        title="Available Workflows"
                        class="hidden sm:flex items-center gap-2 px-3 py-2 text-gray-600 hover:text-forest-600 hover:bg-forest-50 rounded-lg transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                        </svg>
                        <span class="text-sm font-medium">Workflows</span>
                    </button>
                @endif

                {{-- Mood Board Button --}}
                @if($showMoodBoardButton)
                    <button id="btn-mood-board" onclick="toggleMoodBoard()"
                        title="Creative Mood Board"
                        class="relative p-2 text-gray-600 hover:text-forest-600 hover:bg-forest-50 rounded-lg transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/>
                        </svg>
                        <span id="mood-active-dot" class="hidden absolute top-1 right-1 w-2 h-2 bg-forest-500 rounded-full ring-2 ring-white"></span>
                    </button>
                @endif

                {{-- Jobs Button --}}
                @if($showJobsButton)
                    <button onclick="toggleJobsPanel(true)"
                        class="hidden sm:flex items-center gap-2 px-3 py-2 text-gray-600 hover:text-forest-600 hover:bg-forest-50 rounded-lg transition relative">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                        </svg>
                        <span class="text-sm font-medium">Jobs</span>
                        <span id="jobs-badge" class="hidden min-w-[18px] h-[18px] bg-forest-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center px-1"></span>
                    </button>
                @endif

                {{-- Quick Actions Dropdown --}}
                <div class="relative hidden sm:block">
                    <button onclick="toggleQuickActions()" 
                            class="p-2 text-gray-600 hover:text-forest-600 hover:bg-forest-50 rounded-lg transition"
                            title="Quick Actions">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/>
                        </svg>
                    </button>
                    <div id="quick-actions-menu" class="hidden absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-lg border border-gray-200 py-2 z-50">
                        <a href="{{ route('studio.index') }}" class="flex items-center gap-3 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                            New Generation
                        </a>
                        <a href="{{ route('studio.generations') }}" class="flex items-center gap-3 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            My Gallery
                        </a>
                        <div class="border-t border-gray-100 my-2"></div>
                        <a href="#" class="flex items-center gap-3 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            Settings
                        </a>
                        <a href="#" class="flex items-center gap-3 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Help & Docs
                        </a>
                    </div>
                </div>

                {{-- Mobile Menu Button --}}
                <button onclick="toggleMobileMenu()" class="md:hidden p-2 text-gray-600 hover:text-forest-600 hover:bg-forest-50 rounded-lg transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    {{-- Mobile Menu --}}
    <div id="mobile-menu" class="hidden md:hidden border-t border-gray-200 bg-white">
        <div class="px-4 py-3 space-y-1">
            <a href="{{ route('studio.index') }}" 
               class="mobile-nav-link {{ $currentPage === 'studio' ? 'active' : '' }} flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Create New
            </a>
            <a href="{{ route('studio.generations') }}" 
               class="mobile-nav-link {{ $currentPage === 'generations' ? 'active' : '' }} flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                My Gallery
            </a>
            @if($showWorkflowsButton)
            <button onclick="toggleWorkflowsPanel()" class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                </svg>
                Workflows
            </button>
            @endif
            @if($showJobsButton)
            <button onclick="toggleJobsPanel(true)" class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                </svg>
                Jobs Queue
            </button>
            @endif
        </div>
    </div>
</header>

<style>
    .nav-link {
        @apply text-gray-600 hover:text-forest-600 hover:bg-forest-50;
    }
    .nav-link.active {
        @apply text-forest-700 bg-forest-50 font-semibold;
    }
    .mobile-nav-link {
        @apply text-gray-700 hover:bg-gray-50;
    }
    .mobile-nav-link.active {
        @apply text-forest-700 bg-forest-50 font-semibold;
    }
</style>

<script>
    function toggleQuickActions() {
        const menu = document.getElementById('quick-actions-menu');
        menu.classList.toggle('hidden');
    }

    function toggleMobileMenu() {
        const menu = document.getElementById('mobile-menu');
        menu.classList.toggle('hidden');
    }

    // Close dropdowns when clicking outside
    document.addEventListener('click', (e) => {
        const quickActions = document.getElementById('quick-actions-menu');
        if (quickActions && !e.target.closest('[onclick="toggleQuickActions()"]') && !quickActions.contains(e.target)) {
            quickActions.classList.add('hidden');
        }
    });
</script>
