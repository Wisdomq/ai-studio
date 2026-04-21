@extends('layouts.studio')

@section('content')
<div class="min-h-screen flex flex-col bg-gray-50">

    {{-- Header --}}
    <x-studio-navbar currentPage="admin">
        <x-slot:customActions>
            <a href="{{ route('studio.index') }}" class="hidden sm:flex items-center gap-2 px-3 py-2 text-gray-600 hover:text-forest-600 hover:bg-forest-50 rounded-lg transition font-medium text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Studio
            </a>
        </x-slot:customActions>
    </x-studio-navbar>

    <main class="max-w-7xl mx-auto w-full px-4 py-8 space-y-6">

        {{-- Breadcrumbs --}}
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <a href="{{ route('studio.index') }}" class="hover:text-forest-600 transition">Studio</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
            <span class="text-gray-900 font-medium">Admin</span>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
            <span class="text-gray-900 font-medium">Workflows</span>
        </div>

        {{-- Stats Dashboard --}}
        @php
            $totalWorkflows = $workflows->count();
            $activeWorkflows = $workflows->where('is_active', true)->count();
            $imageWorkflows = $workflows->where('output_type', 'image')->count();
            $videoWorkflows = $workflows->where('output_type', 'video')->count();
            $audioWorkflows = $workflows->where('output_type', 'audio')->count();
            $mcpWorkflows = $workflows->filter(fn($w) => !empty($w->mcp_workflow_id))->count();
        @endphp
        
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
            <div class="bg-white border border-gray-200 rounded-xl p-4 hover:shadow-md transition">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-gray-500 uppercase">Total</span>
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                    </svg>
                </div>
                <div class="text-2xl font-bold text-gray-900">{{ $totalWorkflows }}</div>
                <div class="text-xs text-gray-500 mt-1">Workflows</div>
            </div>

            <div class="bg-white border border-green-200 rounded-xl p-4 hover:shadow-md transition">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-green-600 uppercase">Active</span>
                    <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="text-2xl font-bold text-green-700">{{ $activeWorkflows }}</div>
                <div class="text-xs text-gray-500 mt-1">{{ $totalWorkflows > 0 ? round(($activeWorkflows/$totalWorkflows)*100) : 0 }}% enabled</div>
            </div>

            <div class="bg-white border border-blue-200 rounded-xl p-4 hover:shadow-md transition">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-blue-600 uppercase">Images</span>
                    <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <div class="text-2xl font-bold text-blue-700">{{ $imageWorkflows }}</div>
                <div class="text-xs text-gray-500 mt-1">Image gen</div>
            </div>

            <div class="bg-white border border-purple-200 rounded-xl p-4 hover:shadow-md transition">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-purple-600 uppercase">Videos</span>
                    <svg class="w-5 h-5 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                </div>
                <div class="text-2xl font-bold text-purple-700">{{ $videoWorkflows }}</div>
                <div class="text-xs text-gray-500 mt-1">Video gen</div>
            </div>

            <div class="bg-white border border-amber-200 rounded-xl p-4 hover:shadow-md transition">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-amber-600 uppercase">Audio</span>
                    <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"/>
                    </svg>
                </div>
                <div class="text-2xl font-bold text-amber-700">{{ $audioWorkflows }}</div>
                <div class="text-xs text-gray-500 mt-1">Audio gen</div>
            </div>

            <div class="bg-white border border-indigo-200 rounded-xl p-4 hover:shadow-md transition">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-indigo-600 uppercase">MCP</span>
                    <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <div class="text-2xl font-bold text-indigo-700">{{ $mcpWorkflows }}</div>
                <div class="text-xs text-gray-500 mt-1">Live-fetch</div>
            </div>
        </div>

        {{-- Page title + actions --}}
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Workflow Management</h1>
                <p class="text-sm text-gray-500 mt-1">Configure and import AI generation workflows</p>
            </div>
            <button onclick="document.getElementById('import-panel').classList.toggle('hidden')"
                class="flex items-center gap-2 px-5 py-2.5 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-semibold transition shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
                Import Workflow
            </button>
        </div>

        {{-- Search & Filter Bar --}}
        <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
            <div class="flex flex-col md:flex-row gap-3">
                <div class="flex-1">
                    <div class="relative">
                        <input type="text" id="search-workflows" placeholder="Search workflows..." 
                            class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400 focus:ring-2 focus:ring-forest-100">
                        <svg class="w-5 h-5 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>
                </div>
                <select id="filter-output-type" class="px-4 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                    <option value="all">All Types</option>
                    <option value="image">Images</option>
                    <option value="video">Videos</option>
                    <option value="audio">Audio</option>
                </select>
                <select id="filter-capability" class="px-4 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                    <option value="all">All Capabilities</option>
                    @foreach($capabilities->groupBy('category') as $category => $caps)
                        <optgroup label="{{ ucfirst($category) }}">
                            @foreach($caps as $cap)
                                <option value="{{ $cap->slug }}">{{ $cap->name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <select id="filter-source" class="px-4 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                    <option value="all">All Sources</option>
                    <option value="mcp">MCP Live</option>
                    <option value="db">Database</option>
                </select>
                <select id="filter-status" class="px-4 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                    <option value="all">All Status</option>
                    <option value="active">Active Only</option>
                    <option value="inactive">Inactive Only</option>
                </select>
                <span id="results-count" class="flex items-center px-3 text-sm text-gray-500 font-medium">
                    {{ $totalWorkflows }} workflow{{ $totalWorkflows !== 1 ? 's' : '' }}
                </span>
            </div>
        </div>

        {{-- ── Import Panel ────────────────────────────────────────────────── --}}
        <div id="import-panel" class="hidden bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
            <div class="bg-forest-50 border-b border-forest-100 px-5 py-3 flex items-center justify-between">
                <span class="text-sm font-semibold text-forest-800">Import Workflow from ComfyUI</span>
                <div class="flex gap-2">
                    <button onclick="setImportTab('browse')"
                        id="tab-browse"
                        class="import-tab px-3 py-1 rounded-lg text-xs font-medium transition bg-white text-forest-700 border border-forest-300">
                        Browse ComfyUI
                    </button>
                    <button onclick="setImportTab('paste')"
                        id="tab-paste"
                        class="import-tab px-3 py-1 rounded-lg text-xs font-medium transition bg-white text-forest-700 border border-forest-300">
                        Paste JSON
                    </button>
                </div>
            </div>

            {{-- Browse tab --}}
            <div id="import-browse" class="p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-600">Fetch workflows saved in your ComfyUI server.</p>
                    <button id="btn-fetch-comfy" onclick="fetchComfyWorkflows()"
                        class="flex items-center gap-2 px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Fetch from ComfyUI
                    </button>
                </div>

                <div id="comfy-workflow-list" class="hidden space-y-2 max-h-64 overflow-y-auto"></div>
                <div id="comfy-fetch-error" class="hidden text-sm text-red-600 bg-red-50 px-4 py-3 rounded-xl"></div>

                {{-- Metadata form --}}
                <div id="import-metadata-form" class="hidden space-y-4 border-t border-gray-100 pt-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Configure Workflow</p>
                    <input type="hidden" id="import-comfy-path">

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Display Name</label>
                            <input id="import-name" type="text" placeholder="e.g. AnimateDiff Text to Video"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Workflow Type</label>
                            <select id="import-type" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                                <option value="image">Text → Image</option>
                                <option value="video">Text → Video</option>
                                <option value="audio">Text → Audio</option>
                                <option value="image_to_video">Image → Video</option>
                                <option value="video_to_video">Video → Video</option>
                                <option value="avatar_video">Talking Avatar</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Output Type</label>
                            <select id="import-output-type" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                                <option value="image">Image</option>
                                <option value="video">Video</option>
                                <option value="audio">Audio</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Requires File Inputs</label>
                            <select id="import-input-types" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                                <option value="">None (text only)</option>
                                <option value="image">Image</option>
                                <option value="audio">Audio</option>
                                <option value="video">Video</option>
                                <option value="image,audio">Image + Audio</option>
                                <option value="image,video">Image + Video</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Description</label>
                        <input id="import-description" type="text" placeholder="What does this workflow do?"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">
                            File Inject Keys
                            <span class="text-gray-400 font-normal ml-1">— required if this workflow needs file inputs</span>
                        </label>
                        <input id="import-inject-keys" type="text"
                            placeholder='{"image":"@{{INPUT_IMAGE}}"} or {"audio":"@{{INPUT_AUDIO}}"}'
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-mono focus:outline-none focus:border-forest-400">
                        <p class="text-xs text-gray-400 mt-1">Maps media type to the placeholder token used in your workflow JSON.</p>
                    </div>

                    <div>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input id="import-skip-json" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-forest-500 focus:ring-forest-400">
                            <span class="text-xs font-medium text-gray-600">ComfyUI-Direct Mode</span>
                        </label>
                        <p class="text-xs text-gray-400 mt-0.5 ml-6">Fetch workflow JSON from ComfyUI at execution time. No JSON stored in database.</p>
                    </div>

                    <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-xs text-amber-700">
                        <strong>Placeholder reminder:</strong> Before importing, make sure your workflow JSON uses
                        <code class="bg-amber-100 px-1 rounded">@{{ POSITIVE_PROMPT }}</code> for the positive prompt node and
                        <code class="bg-amber-100 px-1 rounded">@{{ NEGATIVE_PROMPT }}</code> for the negative prompt node.
                    </div>

                    <button onclick="submitComfyImport()"
                        class="w-full py-2.5 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-semibold transition">
                        Import Workflow
                    </button>
                </div>
            </div>

            {{-- Paste JSON tab --}}
            <div id="import-paste" class="hidden p-5 space-y-4">
                <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-700">
                    <strong>How to export from ComfyUI:</strong>
                    Open your workflow in ComfyUI → click the menu (⚙️) → <strong>Save (API Format)</strong> → paste the JSON below.
                    This is the most reliable import method.
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Display Name *</label>
                        <input id="paste-name" type="text" placeholder="e.g. AnimateDiff Text to Video"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Workflow Type *</label>
                        <select id="paste-type" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                            <option value="image">Text → Image</option>
                            <option value="video">Text → Video</option>
                            <option value="audio">Text → Audio</option>
                            <option value="image_to_video">Image → Video</option>
                            <option value="video_to_video">Video → Video</option>
                            <option value="avatar_video">Talking Avatar</option>
                            <option value="image_to_image">Image → Image</option>
                            <option value="audio_to_video">Audio → Video</option>
                            <option value="audio_to_audio">Audio → Audio</option>
                            <option value="text_to_speech">Text → Speech</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Output Type *</label>
                        <select id="paste-output-type" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                            <option value="image">Image</option>
                            <option value="video">Video</option>
                            <option value="audio">Audio</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Requires File Inputs</label>
                        <select id="paste-input-types" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                            <option value="">None (text only)</option>
                            <option value="image">Image</option>
                            <option value="audio">Audio</option>
                            <option value="video">Video</option>
                            <option value="image,audio">Image + Audio</option>
                            <option value="image,video">Image + Video</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Description *</label>
                    <input id="paste-description" type="text" placeholder="What does this workflow do?"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">
                        File Inject Keys
                        <span class="text-gray-400 font-normal ml-1">— required if this workflow needs file inputs</span>
                    </label>
                    <input id="paste-inject-keys" type="text"
                        placeholder='{"image":"@{{INPUT_IMAGE}}"} or {"audio":"@{{INPUT_AUDIO}}"}'
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-mono focus:outline-none focus:border-forest-400">
                    <p class="text-xs text-gray-400 mt-1">Maps media type to the placeholder token used in your workflow JSON.</p>
                </div>

                {{-- NEW: MCP Workflow ID field --}}
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">
                        MCP Workflow ID
                        <span class="text-gray-400 font-normal ml-1">— optional, enables live-fetch from MCP sidecar</span>
                    </label>
                    <input id="paste-mcp-workflow-id" type="text"
                        placeholder="e.g. generate_image (leave blank for DB-stored JSON)"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-mono focus:outline-none focus:border-forest-400">
                    <p class="text-xs text-gray-400 mt-1">
                        If set, the graph is fetched live from the MCP sidecar at execution time instead of reading the stored JSON.
                        Must match the filename stem in the MCP server's <code class="bg-gray-100 px-0.5 rounded">workflows/</code> directory.
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">
                        Workflow JSON (API Format) *
                        <span class="text-gray-400 font-normal ml-1">— paste the exported JSON here</span>
                    </label>
                    <textarea id="paste-json" rows="10" placeholder='{"1": {"inputs": {...}, "class_type": "..."}, ...}'
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-mono focus:outline-none focus:border-forest-400 resize-y"></textarea>
                </div>

                <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-xs text-amber-700">
                    <strong>Before pasting:</strong> In your workflow JSON, replace the positive prompt text with
                    <code class="bg-amber-100 px-1 rounded">@{{POSITIVE_PROMPT}}</code> and the negative prompt with
                    <code class="bg-amber-100 px-1 rounded">@{{NEGATIVE_PROMPT}}</code>.
                    For file inputs, use <code class="bg-amber-100 px-1 rounded">@{{INPUT_IMAGE}}</code>,
                    <code class="bg-amber-100 px-1 rounded">@{{INPUT_AUDIO}}</code>, or
                    <code class="bg-amber-100 px-1 rounded">@{{INPUT_VIDEO}}</code>.
                    For numeric fields use <code class="bg-amber-100 px-1 rounded">@{{SEED}}</code>,
                    <code class="bg-amber-100 px-1 rounded">@{{STEPS}}</code>, etc.
                </div>

                <div id="paste-result" class="hidden text-sm px-4 py-3 rounded-xl"></div>

                <button onclick="submitPasteImport()"
                    class="w-full py-2.5 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-semibold transition">
                    Import Workflow
                </button>
            </div>
        </div>

        {{-- ── Edit Workflow Modal ──────────────────────────────────────────── --}}
        <div id="edit-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" onclick="closeEditModal()"></div>
            <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
                <div class="bg-forest-50 border-b border-forest-100 px-5 py-4 flex items-center justify-between">
                    <span class="text-sm font-semibold text-forest-800">Edit Workflow</span>
                    <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <form id="edit-form" class="p-5 space-y-4">
                    <input type="hidden" id="edit-id">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Display Name</label>
                            <input id="edit-name" type="text" required
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Workflow Type</label>
                            <select id="edit-type" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                                <option value="image">Text → Image</option>
                                <option value="video">Text → Video</option>
                                <option value="audio">Text → Audio</option>
                                <option value="image_to_video">Image → Video</option>
                                <option value="video_to_video">Video → Video</option>
                                <option value="avatar_video">Talking Avatar</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Output Type</label>
                            <select id="edit-output-type" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                                <option value="image">Image</option>
                                <option value="video">Video</option>
                                <option value="audio">Audio</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Requires File Inputs</label>
                            <select id="edit-input-types" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                                <option value="">None (text only)</option>
                                <option value="image">Image</option>
                                <option value="audio">Audio</option>
                                <option value="video">Video</option>
                                <option value="image,audio">Image + Audio</option>
                                <option value="image,video">Image + Video</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Description</label>
                        <input id="edit-description" type="text" required
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-forest-400">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">File Inject Keys</label>
                        <input id="edit-inject-keys" type="text"
                            placeholder='{"image": "@{{INPUT_IMAGE}}"}'
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-mono focus:outline-none focus:border-forest-400">
                    </div>
                    {{-- NEW: MCP Workflow ID in edit form --}}
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">
                            MCP Workflow ID
                            <span class="text-gray-400 font-normal ml-1">— enables live-fetch execution path</span>
                        </label>
                        <input id="edit-mcp-workflow-id" type="text"
                            placeholder="e.g. generate_image (leave blank for stored-JSON path)"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-mono focus:outline-none focus:border-forest-400">
                        <p class="text-xs text-gray-400 mt-1">
                            When set, the execution engine fetches the workflow graph live from the MCP sidecar instead of reading stored JSON.
                        </p>
                    </div>
                    <div id="edit-result" class="hidden text-sm px-4 py-3 rounded-xl"></div>
                    <div class="flex gap-3 pt-2">
                        <button type="button" onclick="closeEditModal()"
                            class="flex-1 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl text-sm font-medium transition">
                            Cancel
                        </button>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-semibold transition">
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- ── Node Inspection Modal (NEW) ──────────────────────────────────── --}}
        <div id="nodes-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" onclick="closeNodesModal()"></div>
            <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[85vh] flex flex-col">
                <div class="bg-forest-50 border-b border-forest-100 px-5 py-4 flex items-center justify-between shrink-0">
                    <div>
                        <span class="text-sm font-semibold text-forest-800">Live Node Map</span>
                        <span id="nodes-modal-title" class="text-xs text-forest-600 ml-2"></span>
                    </div>
                    <button onclick="closeNodesModal()" class="text-gray-400 hover:text-gray-600 transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <div class="p-4 shrink-0 border-b border-gray-100">
                    <p class="text-xs text-gray-500">
                        Shows non-linked (editable) inputs for each node. Use node IDs and input keys with
                        <code class="bg-gray-100 px-1 rounded font-mono text-xs">mcpPatchAndSubmit()</code> or as patch targets.
                        Linked inputs (wired from other nodes) are hidden.
                    </p>
                </div>
                <div id="nodes-modal-body" class="flex-1 overflow-y-auto p-4 space-y-2">
                    <div class="text-center text-gray-400 text-sm py-8">Loading nodes from MCP sidecar…</div>
                </div>
                <div class="px-5 py-3 border-t border-gray-100 shrink-0 flex items-center justify-between">
                    <span id="nodes-modal-count" class="text-xs text-gray-400"></span>
                    <button onclick="closeNodesModal()"
                        class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition">
                        Close
                    </button>
                </div>
            </div>
        </div>

        {{-- ── Workflow Table ───────────────────────────────────────────────── --}}
        <div id="sync-message" class="hidden text-sm px-4 py-3 rounded-xl"></div>

        <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-forest-50 border-b border-forest-100">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-forest-700 uppercase tracking-wider">Workflow</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-forest-700 uppercase tracking-wider">Output</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-forest-700 uppercase tracking-wider">Inputs</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-forest-700 uppercase tracking-wider">Source</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-forest-700 uppercase tracking-wider">Active</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-forest-700 uppercase tracking-wider">Default</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-forest-700 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($workflows as $workflow)
                    @php
                        $placeholders = [
                            '{{SEED}}', '{{STEPS}}', '{{CFG}}', '{{WIDTH}}', '{{HEIGHT}}',
                            '{{FRAME_COUNT}}', '{{FPS}}', '{{MOTION_STRENGTH}}', '{{DURATION}}',
                            '{{SAMPLE_RATE}}', '{{DENOISE}}',
                            '"{{SEED}}"', '"{{STEPS}}"', '"{{CFG}}"', '"{{WIDTH}}"', '"{{HEIGHT}}"',
                            '"{{FRAME_COUNT}}"', '"{{FPS}}"', '"{{MOTION_STRENGTH}}"', '"{{DURATION}}"',
                            '"{{SAMPLE_RATE}}"', '"{{DENOISE}}"',
                        ];
                        $values = [
                            '1', '20', '7', '512', '512', '16', '8', '127', '10', '44100', '1',
                            '1', '20', '7', '512', '512', '16', '8', '127', '10', '44100', '1',
                        ];
                        $jsonForDecode = str_replace($placeholders, $values, $workflow->workflow_json ?? '');
                        $decoded   = json_decode($jsonForDecode, true);
                        $nodeCount = is_array($decoded) ? count($decoded) : 0;
                        $hasReal   = $workflow->hasRealWorkflow();
                        $isMcp     = !empty($workflow->mcp_workflow_id);
                        $jsonError = json_last_error_msg();
                        $typeColors = [
                            'image' => 'bg-blue-50 text-blue-700 border-blue-200',
                            'video' => 'bg-purple-50 text-purple-700 border-purple-200',
                            'audio' => 'bg-amber-50 text-amber-700 border-amber-200',
                        ];
                        $col = $typeColors[$workflow->output_type] ?? 'bg-gray-50 text-gray-700 border-gray-200';
                    @endphp
                    <tr class="hover:bg-gray-50 transition" id="wf-row-{{ $workflow->id }}"
                        data-mcp-id="{{ $workflow->mcp_workflow_id ?? '' }}"
                        data-name="{{ addslashes($workflow->name) }}"
                        data-description="{{ addslashes($workflow->description) }}"
                        data-type="{{ $workflow->type }}"
                        data-output-type="{{ $workflow->output_type }}"
                        data-input-types="{{ implode(',', $workflow->input_types ?? []) }}"
                        data-inject-keys="{{ addslashes(json_encode($workflow->inject_keys ?? [])) }}"
                        data-capabilities="{{ $workflow->capabilities->pluck('slug')->implode(',') }}">
                        <td class="px-5 py-4">
                            <div class="font-medium text-gray-800 flex items-center gap-2">
                                {{ $workflow->name }}
                                @if(!$hasReal && !$isMcp)
                                    <span class="text-xs px-1.5 py-0.5 bg-orange-100 text-orange-600 rounded font-medium" title="{{ $jsonError }}">No JSON</span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-400 mt-0.5">{{ Str::limit($workflow->description, 60) }}</div>
                            
                            {{-- Capability Badges --}}
                            @if($workflow->capabilities->isNotEmpty())
                                <div class="flex flex-wrap gap-1 mt-2">
                                    @foreach($workflow->capabilities as $capability)
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-md text-[10px] font-medium"
                                            title="{{ $capability->description }}">
                                            <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                            </svg>
                                            {{ $capability->name }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="px-5 py-4">
                            <span class="px-2.5 py-1 rounded-full text-xs font-medium border {{ $col }}">
                                {{ $workflow->output_type }}
                            </span>
                        </td>
                        <td class="px-5 py-4 text-gray-500 text-xs">
                            {{ empty($workflow->input_types) ? 'Text only' : implode(', ', $workflow->input_types) }}
                        </td>
                        {{-- NEW: Source column --}}
                        <td class="px-5 py-4">
                            @if($isMcp)
                                <div class="flex flex-col gap-0.5">
                                    <span class="inline-flex items-center gap-1 text-xs font-medium text-indigo-700 bg-indigo-50 border border-indigo-200 px-2 py-0.5 rounded-full">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                        </svg>
                                        MCP live
                                    </span>
                                    <span class="text-[10px] text-gray-400 font-mono pl-0.5">{{ $workflow->mcp_workflow_id }}</span>
                                </div>
                            @elseif($nodeCount > 0)
                                <span class="text-xs text-gray-500">{{ $nodeCount }} nodes (DB)</span>
                            @else
                                <span class="text-xs text-red-400" title="{{ $jsonError }}">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-4 text-center">
                            <button
                                class="btn-toggle relative inline-flex h-6 w-11 items-center rounded-full transition-colors duration-200
                                       {{ $workflow->is_active ? 'bg-forest-500' : 'bg-gray-200' }}"
                                data-id="{{ $workflow->id }}">
                                <span class="inline-block h-4 w-4 rounded-full bg-white shadow transition-transform duration-200
                                             {{ $workflow->is_active ? 'translate-x-6' : 'translate-x-1' }}">
                                </span>
                            </button>
                        </td>
                        <td class="px-5 py-4 text-center">
                            @if($workflow->default_for_type)
                                <span class="text-xs font-semibold text-forest-600 bg-forest-50 px-2.5 py-1 rounded-full border border-forest-200">★Default</span>
                            @else
                                <button class="btn-set-default text-xs text-gray-400 hover:text-forest-600 transition font-medium"
                                    data-id="{{ $workflow->id }}">Set default</button>
                            @endif
                        </td>
                        <td class="px-5 py-4 text-center">
                            <div class="flex items-center justify-center gap-1">
                                {{-- NEW: Preview Nodes button (only for MCP live-fetch workflows) --}}
                                @if($isMcp)
                                <button onclick="previewNodes({{ $workflow->id }}, '{{ addslashes($workflow->mcp_workflow_id) }}', '{{ addslashes($workflow->name) }}')"
                                    class="p-1.5 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition"
                                    title="Inspect live nodes from MCP sidecar">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
                                    </svg>
                                </button>
                                @endif
                                <button onclick="editWorkflow({{ $workflow->id }})"
                                    class="p-1.5 text-gray-400 hover:text-forest-600 hover:bg-forest-50 rounded-lg transition" title="Edit">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </button>
                                <button onclick="deleteWorkflow({{ $workflow->id }}, '{{ addslashes($workflow->name) }}')"
                                    class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition" title="Delete">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-5 py-12 text-center text-gray-400">
                            No workflows yet. Import one from ComfyUI using the button above.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </main>
</div>

<script src="{{ asset('js/admin/workflows.js') }}"></script>
<script>
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

const NUMERIC_PLACEHOLDER_NAMES = [
    'SEED', 'STEPS', 'CFG', 'WIDTH', 'HEIGHT',
    'FRAME_COUNT', 'FPS', 'MOTION_STRENGTH', 'DURATION',
    'SAMPLE_RATE', 'DENOISE'
];

function substituteNumericPlaceholders(json) {
    let out = json;
    NUMERIC_PLACEHOLDER_NAMES.forEach(name => {
        out = out.replaceAll('@{{' + name + '}}', '1');
        out = out.replaceAll('{{' + name + '}}', '1');
    });
    return out;
}

// ── Tab switching ─────────────────────────────────────────────────────────────
function setImportTab(tab) {
    document.getElementById('import-browse').classList.toggle('hidden', tab !== 'browse');
    document.getElementById('import-paste').classList.toggle('hidden', tab !== 'paste');
    document.querySelectorAll('.import-tab').forEach(t => t.classList.remove('bg-forest-500', 'text-white'));
    document.getElementById('tab-' + tab).classList.add('bg-forest-500', 'text-white');
}
setImportTab('browse');

// ── Browse: fetch workflow list from ComfyUI ──────────────────────────────────
async function fetchComfyWorkflows() {
    const btn      = document.getElementById('btn-fetch-comfy');
    const listEl   = document.getElementById('comfy-workflow-list');
    const errorEl  = document.getElementById('comfy-fetch-error');

    btn.disabled    = true;
    btn.textContent = 'Fetching...';
    errorEl.classList.add('hidden');
    listEl.classList.add('hidden');

    try {
        const resp = await fetch('{{ route("admin.workflows.comfy-list") }}');
        const data = await resp.json();

        if (data.success && data.workflows.length > 0) {
            listEl.innerHTML = data.workflows.map(w => `
                <button onclick="selectComfyWorkflow('${w.path}', '${w.label}')"
                    class="w-full text-left px-4 py-3 bg-gray-50 hover:bg-forest-50 border border-gray-200 hover:border-forest-300
                           rounded-xl text-sm transition flex items-center justify-between">
                    <span class="font-medium text-gray-800">${w.label}</span>
                    <span class="text-xs text-gray-400">${w.path}</span>
                </button>
            `).join('');
            listEl.classList.remove('hidden');
        } else if (data.success && data.workflows.length === 0) {
            errorEl.textContent = 'No workflows found in ComfyUI. Save some workflows in ComfyUI first, or use the "Paste JSON" tab.';
            errorEl.classList.remove('hidden');
        } else {
            errorEl.textContent = data.error || 'Failed to fetch workflows.';
            errorEl.classList.remove('hidden');
        }
    } catch (e) {
        errorEl.textContent = 'Could not reach ComfyUI. Use the "Paste JSON" tab instead.';
        errorEl.classList.remove('hidden');
    } finally {
        btn.disabled    = false;
        btn.textContent = 'Fetch from ComfyUI';
    }
}

function selectComfyWorkflow(path, label) {
    document.getElementById('import-comfy-path').value = path;
    document.getElementById('import-name').value        = label;
    document.getElementById('import-metadata-form').classList.remove('hidden');
}

async function submitComfyImport() {
    const path          = document.getElementById('import-comfy-path').value;
    const name          = document.getElementById('import-name').value.trim();
    const type          = document.getElementById('import-type').value;
    const outputType    = document.getElementById('import-output-type').value;
    const description   = document.getElementById('import-description').value.trim();
    const inputTypesRaw = document.getElementById('import-input-types').value;
    const inputTypes    = inputTypesRaw ? inputTypesRaw.split(',') : [];
    const injectKeysRaw = document.getElementById('import-inject-keys').value.trim();
    let   injectKeys    = {};
    if (injectKeysRaw) {
        try { injectKeys = JSON.parse(injectKeysRaw); } catch(e) { alert('Invalid JSON in File Inject Keys field.'); return; }
    }

    if (!name || !description) { alert('Please fill in the name and description.'); return; }

    const resp = await fetch('{{ route("admin.workflows.comfy-import") }}', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        body:    JSON.stringify({ path, name, type, output_type: outputType, description, input_types: inputTypes, inject_keys: injectKeys, skip_json: document.getElementById('import-skip-json').checked }),
    });
    const data = await resp.json();

    if (data.success) { alert(`✓ ${data.message}`); location.reload(); }
    else { alert(`✗ Import failed: ${data.error}`); }
}

// ── Paste JSON import ─────────────────────────────────────────────────────────
async function submitPasteImport() {
    const name          = document.getElementById('paste-name').value.trim();
    const type          = document.getElementById('paste-type').value;
    const outputType    = document.getElementById('paste-output-type').value;
    const description   = document.getElementById('paste-description').value.trim();
    const json          = document.getElementById('paste-json').value.trim();
    const mcpWorkflowId = document.getElementById('paste-mcp-workflow-id').value.trim();
    const inputTypesRaw = document.getElementById('paste-input-types').value;
    const inputTypes    = inputTypesRaw ? inputTypesRaw.split(',') : [];
    const injectKeysRaw = document.getElementById('paste-inject-keys').value.trim();
    let   injectKeys    = {};
    if (injectKeysRaw) {
        try { injectKeys = JSON.parse(injectKeysRaw); } catch(e) { alert('Invalid JSON in File Inject Keys field.'); return; }
    }
    const resultEl = document.getElementById('paste-result');

    if (!name || !description || !json) { alert('Please fill in all required fields.'); return; }

    try { JSON.parse(substituteNumericPlaceholders(json)); }
    catch(e) {
        resultEl.textContent = '✗ Invalid JSON: ' + e.message;
        resultEl.className   = 'text-sm px-4 py-3 rounded-xl bg-red-50 text-red-700 border border-red-200';
        resultEl.classList.remove('hidden');
        return;
    }

    const resp = await fetch('{{ route("admin.workflows.comfy-import-json") }}', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        body:    JSON.stringify({
            name, type, output_type: outputType, description, workflow_json: json,
            input_types: inputTypes, inject_keys: injectKeys,
            mcp_workflow_id: mcpWorkflowId || null,
        }),
    });
    const data = await resp.json();

    resultEl.textContent = data.success ? `✓ ${data.message}` : `✗ ${data.error}`;
    resultEl.className   = `text-sm px-4 py-3 rounded-xl ${data.success
        ? 'bg-forest-50 text-forest-700 border border-forest-200'
        : 'bg-red-50 text-red-700 border border-red-200'}`;
    resultEl.classList.remove('hidden');

    if (data.success) setTimeout(() => location.reload(), 1500);
}

// ── Toggle active ─────────────────────────────────────────────────────────────
document.querySelectorAll('.btn-toggle').forEach(btn => {
    btn.addEventListener('click', async () => {
        const resp = await fetch(`/admin/workflows/${btn.dataset.id}/toggle`, {
            method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        });
        const data = await resp.json();
        if (data.success) {
            btn.classList.toggle('bg-forest-500', data.is_active);
            btn.classList.toggle('bg-gray-200', !data.is_active);
            btn.querySelector('span').classList.toggle('translate-x-6', data.is_active);
            btn.querySelector('span').classList.toggle('translate-x-1', !data.is_active);
        }
    });
});

// ── Set default ───────────────────────────────────────────────────────────────
document.querySelectorAll('.btn-set-default').forEach(btn => {
    btn.addEventListener('click', async () => {
        await fetch(`/admin/workflows/${btn.dataset.id}/set-default`, {
            method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        });
        location.reload();
    });
});

// ── Edit workflow ─────────────────────────────────────────────────────────────
function editWorkflow(id) {
    const row = document.getElementById('wf-row-' + id);

    document.getElementById('edit-id').value           = id;
    document.getElementById('edit-name').value         = row.dataset.name;
    document.getElementById('edit-description').value  = row.dataset.description;
    document.getElementById('edit-type').value         = row.dataset.type;
    document.getElementById('edit-output-type').value  = row.dataset.outputType;
    document.getElementById('edit-input-types').value  = row.dataset.inputTypes;
    document.getElementById('edit-mcp-workflow-id').value = row.dataset.mcpId || '';
    document.getElementById('edit-result').classList.add('hidden');

    try {
        const keys = JSON.parse(row.dataset.injectKeys || '{}');
        document.getElementById('edit-inject-keys').value = Object.keys(keys).length ? JSON.stringify(keys) : '';
    } catch(e) {
        document.getElementById('edit-inject-keys').value = '';
    }

    document.getElementById('edit-modal').classList.remove('hidden');
}

function closeEditModal() {
    document.getElementById('edit-modal').classList.add('hidden');
}

document.getElementById('edit-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const id            = document.getElementById('edit-id').value;
    const name          = document.getElementById('edit-name').value.trim();
    const description   = document.getElementById('edit-description').value.trim();
    const type          = document.getElementById('edit-type').value;
    const outputType    = document.getElementById('edit-output-type').value;
    const inputTypesRaw = document.getElementById('edit-input-types').value;
    const inputTypes    = inputTypesRaw ? inputTypesRaw.split(',') : [];
    const mcpWorkflowId = document.getElementById('edit-mcp-workflow-id').value.trim();
    const injectKeysRaw = document.getElementById('edit-inject-keys').value.trim();
    let injectKeys = {};
    if (injectKeysRaw) {
        try { injectKeys = JSON.parse(injectKeysRaw); }
        catch(e) { alert('Invalid JSON in File Inject Keys field.'); return; }
    }

    const resp = await fetch(`/admin/workflows/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        body: JSON.stringify({
            name, description, type, output_type: outputType,
            input_types: inputTypes, inject_keys: injectKeys,
            mcp_workflow_id: mcpWorkflowId || null,
        }),
    });
    const data = await resp.json();
    const resultEl = document.getElementById('edit-result');

    if (data.success) {
        resultEl.textContent = '✓ Workflow updated successfully';
        resultEl.className = 'text-sm px-4 py-3 rounded-xl bg-forest-50 text-forest-700 border border-forest-200';
        resultEl.classList.remove('hidden');
        setTimeout(() => location.reload(), 1000);
    } else {
        resultEl.textContent = '✗ Error: ' + (data.error || 'Unknown error');
        resultEl.className = 'text-sm px-4 py-3 rounded-xl bg-red-50 text-red-700 border border-red-200';
        resultEl.classList.remove('hidden');
    }
});

// ── Delete workflow ───────────────────────────────────────────────────────────
async function deleteWorkflow(id, name) {
    if (!confirm(`Are you sure you want to delete "${name}"? This action cannot be undone.`)) return;

    const formData = new FormData();
    formData.append('_method', 'DELETE');
    formData.append('_token', CSRF_TOKEN);

    const resp = await fetch(`/admin/workflows/${id}`, { method: 'POST', body: formData });
    const data = await resp.json();

    if (data.success) { document.getElementById('wf-row-' + id).remove(); }
    else { alert('Error deleting workflow: ' + (data.error || 'Unknown error')); }
}

// ── NEW: Preview live nodes from MCP sidecar ──────────────────────────────────
async function previewNodes(workflowId, mcpId, workflowName) {
    const modal    = document.getElementById('nodes-modal');
    const body     = document.getElementById('nodes-modal-body');
    const title    = document.getElementById('nodes-modal-title');
    const countEl  = document.getElementById('nodes-modal-count');

    title.textContent = `— ${workflowName} (${mcpId})`;
    body.innerHTML = '<div class="text-center text-gray-400 text-sm py-8">Fetching nodes from MCP sidecar…</div>';
    countEl.textContent = '';
    modal.classList.remove('hidden');

    try {
        const resp = await fetch(`/admin/workflows/${workflowId}/preview-live`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN }
        });
        const data = await resp.json();

        if (!data.success) {
            body.innerHTML = `
                <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-4 text-sm text-red-700">
                    <strong>Error:</strong> ${data.error}
                </div>`;
            return;
        }

        countEl.textContent = `${data.node_count} nodes`;

        // Sort nodes by numeric ID for readability
        const sortedNodes = Object.entries(data.nodes).sort(([a], [b]) => {
            const na = parseInt(a, 10), nb = parseInt(b, 10);
            return (isNaN(na) || isNaN(nb)) ? a.localeCompare(b) : na - nb;
        });

        body.innerHTML = sortedNodes.map(([nodeId, node]) => {
            const inputs = node.inputs || {};
            const inputEntries = Object.entries(inputs);

            // Colour-code class_type by rough category
            const ct = node.class_type || 'unknown';
            const isLoader  = /loader|load/i.test(ct);
            const isSampler = /sampler|ksampler/i.test(ct);
            const isClip    = /clip|text|prompt/i.test(ct);
            const isVae     = /vae/i.test(ct);
            const badgeClass = isLoader  ? 'bg-purple-100 text-purple-700 border-purple-200'
                             : isSampler ? 'bg-blue-100 text-blue-700 border-blue-200'
                             : isClip    ? 'bg-green-100 text-green-700 border-green-200'
                             : isVae     ? 'bg-amber-100 text-amber-700 border-amber-200'
                             :             'bg-gray-100 text-gray-600 border-gray-200';

            return `
            <div class="border border-gray-200 rounded-xl overflow-hidden">
                <div class="bg-gray-50 px-3 py-2 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-bold text-gray-500 font-mono">Node ${nodeId}</span>
                        <span class="text-xs font-medium px-2 py-0.5 rounded-full border ${badgeClass}">${ct}</span>
                    </div>
                    <span class="text-[10px] text-gray-400">${inputEntries.length} editable input${inputEntries.length !== 1 ? 's' : ''}</span>
                </div>
                ${inputEntries.length > 0 ? `
                <div class="divide-y divide-gray-100">
                    ${inputEntries.map(([key, val]) => {
                        const valStr = typeof val === 'object' ? JSON.stringify(val) : String(val);
                        const isPlaceholder = /\{\{[A-Z_]+\}\}/.test(valStr);
                        const valClass = isPlaceholder
                            ? 'text-forest-700 font-semibold'
                            : 'text-gray-600';
                        return `
                        <div class="px-3 py-2 flex items-start justify-between gap-4">
                            <span class="text-xs font-mono text-indigo-600 shrink-0">${key}</span>
                            <span class="text-xs ${valClass} text-right break-all max-w-xs">${valStr}</span>
                        </div>`;
                    }).join('')}
                </div>` : `
                <div class="px-3 py-2 text-xs text-gray-400 italic">No editable inputs (all linked)</div>`}
            </div>`;
        }).join('');

    } catch(e) {
        body.innerHTML = `
            <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-4 text-sm text-red-700">
                <strong>Network error:</strong> ${e.message}
            </div>`;
    }
}

function closeNodesModal() {
    document.getElementById('nodes-modal').classList.add('hidden');
}
</script>
@endsection