@extends('layouts.studio')

@section('content')
<div class="min-h-screen flex flex-col">
    <header class="bg-white border-b border-forest-100 px-6 py-4 flex items-center justify-between sticky top-0 z-50 shadow-sm">
        <a href="{{ route('studio.index') }}" class="flex items-center gap-3 hover:opacity-80 transition">
            <div class="w-8 h-8 bg-forest-500 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                </svg>
            </div>
            <span class="text-lg font-semibold text-gray-900 tracking-tight">AI Studio</span>
        </a>
        <a href="{{ route('studio.index') }}"
           class="text-sm text-gray-500 hover:text-forest-600 transition font-medium flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            New Generation
        </a>
    </header>

    <main class="max-w-5xl mx-auto w-full px-4 py-10">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">My Generations</h1>
                <p class="text-sm text-gray-500 mt-1">Your completed AI-generated media</p>
            </div>
        </div>

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
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                @foreach($plans as $plan)
                    @php
                        // steps are ordered by step_order (from controller eager load)
                        // prefer last completed step, fall back to any step with an output
                        $lastStep = $plan->steps->where('status', 'completed')->last()
                                 ?? $plan->steps->whereNotNull('output_path')->last()
                                 ?? $plan->steps->last();
                        $thumbUrl = $lastStep?->outputUrl() ?? $lastStep?->assetUrl();
                    @endphp
                    <a href="{{ route('studio.result', $plan) }}"
                       class="group bg-white border border-gray-200 rounded-2xl overflow-hidden hover:border-forest-300 hover:shadow-md transition-all duration-200">

                        <div class="aspect-square bg-gray-50 flex items-center justify-center overflow-hidden relative">
                            @if($lastStep && $lastStep->output_path)
                                @php $ext = strtolower(pathinfo($lastStep->output_path, PATHINFO_EXTENSION)); @endphp
                                @if(in_array($ext, ['mp4', 'webm', 'mov']))
                                    <div class="w-full h-full flex items-center justify-center bg-gray-900">
                                        <svg class="w-10 h-10 text-white opacity-60" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M8 5v14l11-7z"/>
                                        </svg>
                                    </div>
                                @elseif(in_array($ext, ['mp3', 'wav', 'ogg', 'flac']))
                                    <div class="flex flex-col items-center gap-2 text-forest-400">
                                        <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"/>
                                        </svg>
                                        <span class="text-xs font-medium">Audio</span>
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
                                <p class="text-xs text-gray-400 mt-1.5">{{ $plan->created_at->diffForHumans() }}</p>
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
                @endforeach
            </div>

            <div class="mt-8">{{ $plans->links() }}</div>
        @endif
    </main>
</div>
@endsection