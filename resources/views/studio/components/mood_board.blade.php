{{-- ── Creative Mood Board (shown during generation wait) ──────────────────── --}}
<div id="mood-board-overlay" class="hidden fixed inset-0 z-30 bg-black/20 backdrop-blur-sm flex items-end justify-center pb-6 px-4">
    <div id="mood-board" class="slide-in bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-gray-200">

        <div class="bg-gradient-to-r from-forest-500 to-forest-600 px-5 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="font-semibold text-white text-sm">Creative Mood Board</h3>
                    <p class="text-forest-100 text-xs mt-0.5">Shape your next generation while you wait ✨</p>
                </div>
                <button onclick="closeMoodBoard()"
                    class="text-forest-200 hover:text-white transition p-1 rounded-lg hover:bg-white/10">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>

        <div class="p-5 space-y-5">

            {{-- Color palette --}}
            <div>
                <label class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2 block">Color Palette</label>
                <div class="flex gap-2 flex-wrap">
                    @foreach([
                        ['#FF6B6B','Warm Red'],['#FF8E53','Sunset Orange'],['#FFD93D','Golden'],
                        ['#6BCB77','Forest Green'],['#4D96FF','Ocean Blue'],['#C77DFF','Lavender'],
                        ['#1A1A2E','Deep Night'],['#F8F9FA','Soft White'],['#2D3436','Charcoal'],
                        ['#FFC8DD','Blush Pink'],['#A8DADC','Seafoam'],['#457B9D','Slate Blue'],
                    ] as [$color, $name])
                    <button
                        class="mood-swatch w-9 h-9 rounded-full border-2 border-white shadow-md hover:shadow-lg"
                        style="background:{{ $color }}"
                        data-color="{{ $color }}"
                        data-name="{{ $name }}"
                        title="{{ $name }}"
                        onclick="toggleMoodColor(this)">
                    </button>
                    @endforeach
                </div>
            </div>

            {{-- Style tags --}}
            <div>
                <label class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2 block">Visual Style</label>
                <div class="flex gap-2 flex-wrap">
                    @foreach(['Cinematic','Minimalist','Dreamy','Gritty','Neon','Vintage','Ethereal','Bold','Soft','Dramatic','Abstract','Realistic','Painterly','Lo-fi'] as $style)
                    <button
                        class="mood-tag text-xs px-3 py-1.5 rounded-full border border-gray-200 text-gray-600 hover:border-forest-400 hover:text-forest-700 transition"
                        data-tag="{{ $style }}"
                        onclick="toggleMoodTag(this)">
                        {{ $style }}
                    </button>
                    @endforeach
                </div>
            </div>

            {{-- Mood / Energy --}}
            <div>
                <label class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2 block">Mood & Energy</label>
                <div class="flex gap-2 flex-wrap">
                    @foreach(['Calm','Energetic','Melancholic','Joyful','Mysterious','Tense','Serene','Wild','Nostalgic','Futuristic'] as $mood)
                    <button
                        class="mood-tag text-xs px-3 py-1.5 rounded-full border border-gray-200 text-gray-600 hover:border-forest-400 hover:text-forest-700 transition"
                        data-tag="{{ $mood }}"
                        onclick="toggleMoodTag(this)">
                        {{ $mood }}
                    </button>
                    @endforeach
                </div>
            </div>

            {{-- Current selections preview --}}
            <div id="mood-preview" class="hidden bg-forest-50 rounded-xl px-4 py-3 border border-forest-100">
                <div class="text-xs font-semibold text-forest-700 mb-1">Style hint for next prompt:</div>
                <div id="mood-preview-text" class="text-xs text-forest-600 italic"></div>
            </div>

            {{-- Actions --}}
            <div class="flex gap-3">
                <button onclick="applyMoodBoard()"
                    class="flex-1 py-2.5 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-medium transition">
                    Apply to Next Prompt
                </button>
                <button onclick="clearMoodBoard()"
                    class="px-4 py-2.5 bg-white hover:bg-gray-50 text-gray-600 border border-gray-200 rounded-xl text-sm font-medium transition">
                    Clear
                </button>
            </div>
        </div>
    </div>
</div>