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

    <main class="max-w-3xl mx-auto w-full px-4 py-10 space-y-6">
        <div class="slide-in">
            <div class="flex items-center gap-2 mb-1">
                <div class="w-2 h-2 bg-amber-500 rounded-full animate-pulse"></div>
                <span class="text-xs font-semibold text-amber-600 uppercase tracking-widest">Review Required</span>
            </div>
            <h1 class="text-xl font-semibold text-gray-900">{{ $plan->user_intent }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $plan->steps->where('status', 'awaiting_approval')->count() }} step(s) pending your review</p>
        </div>

        @foreach($plan->steps as $step)
            @if($step->status === 'awaiting_approval' || $step->status === 'completed')
            <div class="slide-in bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm"
                 style="animation-delay: {{ $loop->index * 0.1 }}s">
                <div class="bg-forest-50 border-b border-forest-100 px-5 py-3 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-forest-800">{{ $step->purpose }}</span>
                        @if($step->status === 'awaiting_approval')
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-amber-100 text-amber-700 border border-amber-200">Awaiting Review</span>
                        @else
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-green-100 text-green-700 border border-green-200">Approved</span>
                        @endif
                    </div>
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
                @elseif($step->status === 'awaiting_approval')
                    <div class="px-5 py-8 text-center text-gray-400 text-sm">No output available</div>
                @endif

                @if($step->status === 'awaiting_approval')
                    <div class="px-5 py-4 bg-gray-50 border-t border-gray-100 flex items-center gap-3">
                        <form action="{{ route('studio.plan.step.approve', ['plan' => $plan->id, 'order' => $step->step_order]) }}" 
                              method="POST" class="flex-1">
                            @csrf
                            <button type="submit" 
                                    class="w-full py-2.5 px-4 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-semibold transition shadow-sm flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                Approve & Continue
                            </button>
                        </form>
                        <form action="{{ route('studio.plan.step.reject', ['plan' => $plan->id, 'order' => $step->step_order]) }}" 
                              method="POST" class="flex-1">
                            @csrf
                            <button type="submit" 
                                    class="w-full py-2.5 px-4 bg-white hover:bg-red-50 text-red-600 border border-red-200 hover:border-red-300 rounded-xl text-sm font-semibold transition flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                                Request Changes
                            </button>
                        </form>
                    </div>
                @endif
            </div>
            @endif
        @endforeach

        @if($plan->steps->every(fn($s) => $s->status === 'completed'))
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
        @endif
    </main>
</div>
@endsection
