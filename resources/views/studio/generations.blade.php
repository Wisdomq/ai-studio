@extends('layouts.studio')

@push('scripts')
<script src="{{ asset('js/studio/generations.js') }}"></script>
@endpush

@section('content')
<div class="min-h-screen flex flex-col">
<x-studio-navbar currentPage="generations" />

    <main class="max-w-5xl mx-auto w-full px-4 py-10">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">My Generations</h1>
                <p class="text-sm text-gray-500 mt-1">Your completed AI-generated media</p>
            </div>
        </div>

        {{-- Filters & Controls --}}
        @if(!$plans->isEmpty())
        <div class="bg-white border border-gray-200 rounded-xl p-4 mb-6 flex flex-wrap items-center gap-4">
            {{-- Search --}}
            <div class="flex-1 min-w-[200px]">
                <div class="relative">
                    <input type="text" id="search-input" placeholder="Search generations..." 
                        class="w-full pl-10 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400 focus:ring-1 focus:ring-forest-400">
                    <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>
            </div>

            {{-- Filter by Type --}}
            <select id="filter-type" class="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                <option value="all">All Types</option>
                <option value="image">Images</option>
                <option value="video">Videos</option>
                <option value="audio">Audio</option>
            </select>

            {{-- Sort --}}
            <select id="sort-by" class="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                <option value="newest">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>

            {{-- View Toggle --}}
            <div class="flex items-center gap-1 bg-gray-100 rounded-lg p-1">
                <button id="view-grid" onclick="setView('grid')" class="view-toggle active px-3 py-1.5 rounded-md text-xs font-medium transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                    </svg>
                </button>
                <button id="view-list" onclick="setView('list')" class="view-toggle px-3 py-1.5 rounded-md text-xs font-medium transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            </div>

            {{-- Results Count --}}
            <span id="results-count" class="text-xs text-gray-500 font-medium">
                {{ $plans->total() }} generation{{ $plans->total() !== 1 ? 's' : '' }}
            </span>
        </div>
        @endif

        @if($plans->isEmpty())
            <div class="text-center py-24 bg-white rounded-2xl border border-gray-200">
                <div class="w-16 h-16 bg-forest-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-forest-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <p class="text-gray-600 font-medium">No generations yet</p>
                <p class="text-gray-400 text-sm mt-1">Create your first AI generation to see it here</p>
                <a href="{{ route('studio.index') }}"
                   class="mt-5 inline-flex items-center gap-2 px-5 py-2.5 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-medium transition shadow-sm">
                    Start Creating
                </a>
            </div>
        @else
            <div id="generations-grid" class="grid grid-cols-2 md:grid-cols-3 gap-4">
                @foreach($plans as $plan)
                    @php
                        // steps are ordered by step_order (from controller eager load)
                        // prefer last completed step, fall back to any step with an output
                        $lastStep = $plan->steps->where('status', 'completed')->last()
                                 ?? $plan->steps->whereNotNull('output_path')->last()
                                 ?? $plan->steps->last();
                        $thumbUrl = $lastStep?->outputUrl() ?? $lastStep?->assetUrl();
                        $ext = $lastStep && $lastStep->output_path ? strtolower(pathinfo($lastStep->output_path, PATHINFO_EXTENSION)) : null;
                        
                        // Determine media type
                        $mediaType = 'image';
                        if ($ext && in_array($ext, ['mp4', 'webm', 'mov'])) {
                            $mediaType = 'video';
                        } elseif ($ext && in_array($ext, ['mp3', 'wav', 'ogg', 'flac'])) {
                            $mediaType = 'audio';
                        }
                    @endphp
                    <div class="generation-item" data-type="{{ $mediaType }}" data-date="{{ $plan->created_at->timestamp }}" data-title="{{ strtolower($plan->user_intent) }}">
                        <a href="{{ route('studio.result', $plan) }}"
                           class="group bg-white border-2 border-gray-200 rounded-2xl overflow-hidden hover:border-forest-400 hover:shadow-lg transition-all duration-200 block">

                            <div class="aspect-square bg-gray-50 flex items-center justify-center overflow-hidden relative">
                                {{-- Media Type Badge --}}
                                <div class="absolute top-2 right-2 z-10">
                                    @if($mediaType === 'video')
                                        <span class="inline-flex items-center gap-1 px-2 py-1 bg-purple-500 text-white rounded-lg text-xs font-medium shadow-sm">
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M8 5v14l11-7z"/>
                                            </svg>
                                            Video
                                        </span>
                                    @elseif($mediaType === 'audio')
                                        <span class="inline-flex items-center gap-1 px-2 py-1 bg-amber-500 text-white rounded-lg text-xs font-medium shadow-sm">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"/>
                                            </svg>
                                            Audio
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2 py-1 bg-blue-500 text-white rounded-lg text-xs font-medium shadow-sm">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                            </svg>
                                            Image
                                        </span>
                                    @endif
                                </div>

                                @if($lastStep && $lastStep->output_path)
                                    @if($mediaType === 'video')
                                        <div class="w-full h-full flex items-center justify-center bg-gray-900 relative">
                                            @if($thumbUrl)
                                                <img src="{{ $thumbUrl }}" alt="{{ $plan->user_intent }}"
                                                     class="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                                                     onerror="this.style.display='none'">
                                            @endif
                                            <div class="absolute inset-0 flex items-center justify-center">
                                                <div class="w-12 h-12 bg-white/90 rounded-full flex items-center justify-center group-hover:scale-110 transition">
                                                    <svg class="w-6 h-6 text-gray-900 ml-1" fill="currentColor" viewBox="0 0 24 24">
                                                        <path d="M8 5v14l11-7z"/>
                                                    </svg>
                                                </div>
                                            </div>
                                        </div>
                                    @elseif($mediaType === 'audio')
                                        <div class="flex flex-col items-center gap-3 text-forest-500">
                                            <div class="w-16 h-16 bg-forest-100 rounded-full flex items-center justify-center group-hover:scale-110 transition">
                                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"/>
                                                </svg>
                                            </div>
                                            <span class="text-sm font-medium">Audio File</span>
                                        </div>
                                    @elseif($thumbUrl)
                                        <img src="{{ $thumbUrl }}" alt="{{ $plan->user_intent }}"
                                             class="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                                             onerror="this.parentElement.innerHTML='<div class=\'w-full h-full flex items-center justify-center text-gray-300\'><svg class=\'w-10 h-10\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z\'/></svg></div>'">
                                    @endif
                                @else
                                    <div class="text-gray-300">
                                        <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                    </div>
                                @endif
                            </div>

                            <div class="p-3 border-t border-gray-100 flex items-start justify-between gap-2">
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs text-gray-700 font-medium line-clamp-2 leading-relaxed">{{ $plan->user_intent }}</p>
                                    <p class="text-xs text-gray-400 mt-1.5 flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        {{ $plan->created_at->diffForHumans() }}
                                    </p>
                                </div>
                                <form action="{{ route('studio.plan.destroy', $plan) }}" method="POST" class="shrink-0"
                                      onsubmit="return confirm('Delete this generation?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="p-1.5 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition" title="Delete">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </a>
                    </div>
                @endforeach
            </div>

            <div class="mt-8">{{ $plans->links() }}</div>
        @endif
    </main>
</div>
@endsection