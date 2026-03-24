@extends('layouts.studio')

@section('content')
<div class="min-h-screen flex flex-col">
    <header class="bg-white border-b border-forest-100 px-6 py-4 flex items-center justify-between sticky top-0 z-50 shadow-sm">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 bg-forest-500 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                </svg>
            </div>
            <span class="text-lg font-semibold text-gray-900 tracking-tight">AI Studio</span>
        </div>
        <a href="{{ route('studio.index') }}"
           class="text-sm text-gray-500 hover:text-forest-600 transition font-medium flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            New Generation
        </a>
    </header>

    <main class="max-w-2xl mx-auto w-full px-4 py-10 space-y-6">

        <div class="slide-in">
            <div class="flex items-center gap-2 mb-1">
                <div class="w-2 h-2 bg-forest-500 rounded-full"></div>
                <span class="text-xs font-semibold text-forest-600 uppercase tracking-widest">Generation Complete</span>
            </div>
            <h1 class="text-xl font-semibold text-gray-900">{{ $plan->user_intent }}</h1>
        </div>

        @foreach($plan->steps as $step)
        <div class="slide-in bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm"
             style="animation-delay: {{ $loop->index * 0.1 }}s">
            <div class="bg-forest-50 border-b border-forest-100 px-5 py-3 flex items-center justify-between">
                <span class="text-sm font-semibold text-forest-800">{{ $step->purpose }}</span>
                <span class="text-xs px-2.5 py-1 rounded-full font-medium bg-forest-100 text-forest-700 border border-forest-200">
                    {{ $step->workflow_type }}
                </span>
            </div>

            @if($step->output_path)
                @php
                    $url = $step->outputUrl();
                    $ext = pathinfo($step->output_path, PATHINFO_EXTENSION);
                @endphp

                <div class="bg-gray-50">
                    @if(in_array($ext, ['mp4', 'webm', 'mov']))
                        <video controls class="w-full max-h-96 object-contain" src="{{ $url }}"></video>
                    @elseif(in_array($ext, ['mp3', 'wav', 'ogg', 'flac']))
                        <div class="p-6 flex items-center justify-center">
                            <audio controls class="w-full" src="{{ $url }}"></audio>
                        </div>
                    @else
                        <img src="{{ $url }}" alt="{{ $step->purpose }}" class="w-full max-h-96 object-contain">
                    @endif
                </div>

                <div class="px-5 py-3 border-t border-gray-100 flex items-center justify-between">
                    <span class="text-xs text-gray-400">{{ basename($step->output_path) }}</span>
                    <a href="{{ $url }}" download
                       class="flex items-center gap-1.5 text-sm text-forest-600 hover:text-forest-700 font-medium transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        Download
                    </a>
                </div>
            @else
                <div class="px-5 py-8 text-center text-gray-400 text-sm">No output available</div>
            @endif
        </div>
        @endforeach

        <div class="flex gap-3 pt-2">
            <a href="{{ route('studio.index') }}"
               class="flex-1 py-3 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-semibold text-center transition shadow-sm">
                Create Another
            </a>
            <a href="{{ route('studio.generations') }}"
               class="flex-1 py-3 bg-white hover:bg-gray-50 text-gray-700 border border-gray-200 rounded-xl text-sm font-semibold text-center transition">
                My Generations
            </a>
        </div>
    </main>
</div>
@endsection