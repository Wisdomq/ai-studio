@extends('layouts.studio')

@push('scripts')
<script src="{{ asset('js/studio/result.js') }}"></script>
@endpush

@section('content')
<div class="min-h-screen flex flex-col bg-gray-50">
<x-studio-navbar currentPage="result">
    <x-slot:customActions>
        <button onclick="downloadAll()" class="hidden sm:flex items-center gap-2 px-3 py-2 text-gray-600 hover:text-forest-600 hover:bg-forest-50 rounded-lg transition font-medium text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Download All
        </button>
    </x-slot:customActions>
</x-studio-navbar>

    <main class="max-w-4xl mx-auto w-full px-4 py-8 space-y-6">

        {{-- Generation Header --}}
        <div class="slide-in bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div class="flex-1">
                    <div class="flex items-center gap-2 mb-2">
                        <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                        <span class="text-xs font-semibold text-green-600 uppercase tracking-widest">Generation Complete</span>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-900 mb-2">{{ $plan->user_intent }}</h1>
                    <div class="flex items-center gap-4 text-sm text-gray-500">
                        <span class="flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            {{ $plan->created_at->diffForHumans() }}
                        </span>
                        <span class="flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/>
                            </svg>
                            {{ $plan->steps->count() }} step{{ $plan->steps->count() !== 1 ? 's' : '' }}
                        </span>
                    </div>
                </div>
                <div class="flex flex-col gap-2">
                    <span class="px-3 py-1.5 bg-green-100 text-green-700 border border-green-200 rounded-lg text-xs font-semibold text-center">
                        ✓ Completed
                    </span>
                </div>
            </div>
        </div>

        {{-- Timeline View (if multiple steps) --}}
        @if($plan->steps->count() > 1)
        <div class="slide-in bg-white border border-gray-200 rounded-2xl p-6 shadow-sm" style="animation-delay: 0.1s">
            <h2 class="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-forest-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
                Generation Pipeline
            </h2>
            <div class="flex items-center gap-3 overflow-x-auto pb-2">
                @foreach($plan->steps as $step)
                    <div class="flex items-center gap-3 shrink-0">
                        <div class="flex flex-col items-center">
                            <div class="w-10 h-10 bg-green-100 border-2 border-green-500 rounded-full flex items-center justify-center text-sm font-bold text-green-700">
                                {{ $loop->iteration }}
                            </div>
                            <span class="text-xs text-gray-500 mt-1 max-w-[80px] text-center truncate">{{ $step->workflow_type }}</span>
                        </div>
                        @if(!$loop->last)
                            <svg class="w-6 h-6 text-gray-300 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                            </svg>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Step Results --}}
        @foreach($plan->steps as $step)
        <div class="slide-in bg-white border-2 border-gray-200 rounded-2xl overflow-hidden shadow-sm hover:shadow-md transition-shadow"
             style="animation-delay: {{ ($loop->index + 2) * 0.1 }}s">
            
            {{-- Step Header --}}
            <div class="bg-gradient-to-r from-forest-50 to-white border-b border-gray-200 px-6 py-4">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-start gap-3 flex-1">
                        <div class="w-8 h-8 bg-forest-500 text-white rounded-full flex items-center justify-center text-sm font-bold shrink-0 mt-0.5">
                            {{ $loop->iteration }}
                        </div>
                        <div class="flex-1">
                            <h3 class="text-base font-semibold text-gray-900 mb-1">{{ $step->purpose }}</h3>
                            <div class="flex items-center gap-2 flex-wrap">
                                @php
                                    $typeColors = [
                                        'image' => 'bg-blue-100 text-blue-700 border-blue-200',
                                        'video' => 'bg-purple-100 text-purple-700 border-purple-200',
                                        'audio' => 'bg-amber-100 text-amber-700 border-amber-200',
                                    ];
                                    $typeColor = $typeColors[$step->workflow_type] ?? 'bg-gray-100 text-gray-700 border-gray-200';
                                @endphp
                                <span class="text-xs px-2.5 py-1 rounded-full font-semibold border {{ $typeColor }}">
                                    {{ ucfirst($step->workflow_type) }}
                                </span>
                                @if($step->workflow)
                                    <span class="text-xs text-gray-500">{{ $step->workflow->name }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <button onclick="toggleDetails({{ $step->id }})" class="text-gray-400 hover:text-gray-600 transition p-1">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </button>
                </div>
            </div>

            {{-- Media Output --}}
            @if($step->output_path)
                @php
                    $url = $step->outputUrl();
                    $ext = strtolower(pathinfo($step->output_path, PATHINFO_EXTENSION));
                @endphp

                <div class="bg-gray-900 relative group">
                    @if(in_array($ext, ['mp4', 'webm', 'mov']))
                        <video controls class="w-full max-h-[500px] object-contain" src="{{ $url }}" preload="metadata"></video>
                        <button onclick="openLightbox('{{ $url }}', 'video')" 
                                class="absolute top-4 right-4 bg-black/50 hover:bg-black/70 text-white p-2 rounded-lg opacity-0 group-hover:opacity-100 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                            </svg>
                        </button>
                    @elseif(in_array($ext, ['mp3', 'wav', 'ogg', 'flac']))
                        <div class="p-12 flex flex-col items-center justify-center gap-4">
                            <div class="w-20 h-20 bg-forest-100 rounded-full flex items-center justify-center">
                                <svg class="w-10 h-10 text-forest-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"/>
                                </svg>
                            </div>
                            <audio controls class="w-full max-w-md" src="{{ $url }}"></audio>
                        </div>
                    @else
                        <img src="{{ $url }}" alt="{{ $step->purpose }}" class="w-full max-h-[500px] object-contain cursor-pointer"
                             onclick="openLightbox('{{ $url }}', 'image')">
                        <button onclick="openLightbox('{{ $url }}', 'image')" 
                                class="absolute top-4 right-4 bg-black/50 hover:bg-black/70 text-white p-2 rounded-lg opacity-0 group-hover:opacity-100 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                            </svg>
                        </button>
                    @endif
                </div>

                {{-- Step Footer --}}
                <div class="px-6 py-4 border-t border-gray-100 bg-gray-50">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-4 text-xs text-gray-500">
                            <span class="flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                </svg>
                                {{ basename($step->output_path) }}
                            </span>
                            @if($step->approved_at)
                            <span class="flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Completed {{ $step->approved_at->diffForHumans() }}
                            </span>
                            @endif
                        </div>
                        <a href="{{ $url }}" download
                           class="flex items-center gap-1.5 text-sm text-forest-600 hover:text-forest-700 font-semibold transition px-3 py-1.5 bg-forest-50 hover:bg-forest-100 rounded-lg">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            Download
                        </a>
                    </div>
                </div>

                {{-- Collapsible Details --}}
                <div id="details-{{ $step->id }}" class="hidden border-t border-gray-200 bg-gray-50 px-6 py-4">
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <span class="text-gray-500 font-medium">Status:</span>
                            <span class="ml-2 text-gray-900">{{ ucfirst($step->status) }}</span>
                        </div>
                        @if($step->workflow)
                        <div>
                            <span class="text-gray-500 font-medium">Workflow:</span>
                            <span class="ml-2 text-gray-900">{{ $step->workflow->name }}</span>
                        </div>
                        @endif
                        @if($step->execution_layer !== null)
                        <div>
                            <span class="text-gray-500 font-medium">Execution Layer:</span>
                            <span class="ml-2 text-gray-900">{{ $step->execution_layer }}</span>
                        </div>
                        @endif
                        @if($step->depends_on && count($step->depends_on) > 0)
                        <div>
                            <span class="text-gray-500 font-medium">Dependencies:</span>
                            <span class="ml-2 text-gray-900">Step {{ implode(', ', array_map(fn($d) => is_array($d) ? $d : $d + 1, $step->depends_on)) }}</span>
                        </div>
                        @endif
                        @if($step->refined_prompt)
                        <div class="col-span-2">
                            <span class="text-gray-500 font-medium">Prompt:</span>
                            <p class="mt-1 text-gray-700 text-xs bg-white p-3 rounded-lg border border-gray-200">{{ $step->refined_prompt }}</p>
                        </div>
                        @endif
                    </div>
                </div>
            @else
                <div class="px-6 py-12 text-center">
                    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">
                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <p class="text-gray-500 text-sm font-medium">No output available</p>
                    <p class="text-gray-400 text-xs mt-1">This step did not generate any output</p>
                </div>
            @endif
        </div>
        @endforeach

        {{-- Action Buttons --}}
        <div class="flex gap-3 pt-2">
            <a href="{{ route('studio.index') }}"
               class="flex-1 py-3.5 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-semibold text-center transition shadow-sm flex items-center justify-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Create Another
            </a>
            <a href="{{ route('studio.generations') }}"
               class="flex-1 py-3.5 bg-white hover:bg-gray-50 text-gray-700 border-2 border-gray-200 rounded-xl text-sm font-semibold text-center transition flex items-center justify-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                </svg>
                My Generations
            </a>
        </div>
    </main>

    {{-- Lightbox Modal --}}
    <div id="lightbox" class="hidden fixed inset-0 bg-black/90 z-[100] flex items-center justify-center p-4" onclick="closeLightbox()">
        <button onclick="closeLightbox()" class="absolute top-4 right-4 text-white hover:text-gray-300 transition p-2">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
        <div id="lightbox-content" class="max-w-7xl max-h-full" onclick="event.stopPropagation()">
            {{-- Content injected by JS --}}
        </div>
    </div>
</div>
@endsection