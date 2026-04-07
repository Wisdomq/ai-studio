@extends('layouts.studio')

@section('content')
<div class="min-h-screen flex flex-col">

    {{-- Header --}}
    <header class="bg-white border-b border-forest-100 px-6 py-4 flex items-center justify-between sticky top-0 z-50 shadow-sm">
        <a href="{{ route('studio.index') }}" class="flex items-center gap-3 hover:opacity-80 transition">
            <div class="w-8 h-8 bg-forest-500 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
            <div>
                <span class="text-lg font-semibold text-gray-900">AI Studio</span>
                <span class="text-sm text-gray-400 ml-2">Admin</span>
            </div>
        </a>
        <a href="{{ route('studio.index') }}" class="text-sm text-gray-500 hover:text-forest-600 transition font-medium">← Studio</a>
    </header>

    <main class="max-w-5xl mx-auto w-full px-4 py-10 space-y-8">

        {{-- Page title + actions --}}
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Workflows</h1>
                <p class="text-sm text-gray-500 mt-1">Manage generation workflows and import from ComfyUI</p>
            </div>
            <button onclick="document.getElementById('import-panel').classList.toggle('hidden')"
                class="flex items-center gap-2 px-5 py-2.5 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-medium transition shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
                Import Workflow
            </button>
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

                {{-- Metadata form (shown after selecting a workflow) --}}
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

                    <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-xs text-amber-700">
                        <strong>Placeholder reminder:</strong> Before importing, make sure your workflow JSON uses
                        <code class="bg-amber-100 px-1 rounded">@{{ POSITIVE_PROMPT }}</code> for the positive prompt node and
                        <code class="bg-amber-100 px-1 rounded">@{{ NEGATIVE_PROMPT }}</code> for the negative prompt node.
                        These are injected at generation time.
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
                    For numeric fields (seed, steps, cfg, width, height, etc.) you may use
                    <code class="bg-amber-100 px-1 rounded">@{{SEED}}</code>,
                    <code class="bg-amber-100 px-1 rounded">@{{STEPS}}</code>,
                    <code class="bg-amber-100 px-1 rounded">@{{CFG}}</code>, etc. — these are injected as bare numbers at generation time.
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

        {{-- ── Workflow Table ───────────────────────────────────────────────── --}}
        <div id="sync-message" class="hidden text-sm px-4 py-3 rounded-xl"></div>

        <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-forest-50 border-b border-forest-100">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-forest-700 uppercase tracking-wider">Workflow</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-forest-700 uppercase tracking-wider">Output</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-forest-700 uppercase tracking-wider">Inputs</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-forest-700 uppercase tracking-wider">Nodes</th>
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
                        $jsonForDecode = str_replace($placeholders, $values, $workflow->workflow_json);
                        $decoded    = json_decode($jsonForDecode, true);
                        $nodeCount  = is_array($decoded) ? count($decoded) : 0;
                        $hasReal    = $workflow->hasRealWorkflow();
                        $jsonError  = json_last_error_msg();
                        $typeColors = [
                            'image' => 'bg-blue-50 text-blue-700 border-blue-200',
                            'video' => 'bg-purple-50 text-purple-700 border-purple-200',
                            'audio' => 'bg-amber-50 text-amber-700 border-amber-200',
                        ];
                        $col = $typeColors[$workflow->output_type] ?? 'bg-gray-50 text-gray-700 border-gray-200';
                    @endphp
                    <tr class="hover:bg-gray-50 transition" id="wf-row-{{ $workflow->id }}">
                        <td class="px-5 py-4">
                            <div class="font-medium text-gray-800 flex items-center gap-2">
                                {{ $workflow->name }}
                                @if(!$hasReal)
                                    <span class="text-xs px-1.5 py-0.5 bg-orange-100 text-orange-600 rounded font-medium" title="{{ $jsonError }}">No JSON</span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-400 mt-0.5">{{ Str::limit($workflow->description, 60) }}</div>
                        </td>
                        <td class="px-5 py-4">
                            <span class="px-2.5 py-1 rounded-full text-xs font-medium border {{ $col }}">
                                {{ $workflow->output_type }}
                            </span>
                        </td>
                        <td class="px-5 py-4 text-gray-500 text-xs">
                            {{ empty($workflow->input_types) ? 'Text only' : implode(', ', $workflow->input_types) }}
                        </td>
                        <td class="px-5 py-4 text-gray-500 text-xs">
                            @if($nodeCount > 0)
                                {{ $nodeCount }} nodes
                            @else
                                —
                                <br><span class="text-red-500 text-[10px]" title="{{ $jsonError }}">JSON: {{ $jsonError }}</span>
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
                            <div class="flex items-center justify-center gap-2">
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

<script>
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

// ── Numeric placeholders that appear as bare values in workflow JSON ──────────
// These are not valid JSON tokens on their own, so we substitute dummy numbers
// before client-side JSON.parse() validation. The original string (with
// placeholders intact) is always what gets sent to the server.
//
// This allows users to use placeholders for numeric fields in their workflow JSON
// form so validation passes regardless of which the user typed.
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

    btn.disabled   = true;
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
    const path        = document.getElementById('import-comfy-path').value;
    const name        = document.getElementById('import-name').value.trim();
    const type        = document.getElementById('import-type').value;
    const outputType  = document.getElementById('import-output-type').value;
    const description = document.getElementById('import-description').value.trim();
    const inputTypesRaw = document.getElementById('import-input-types').value;
    const inputTypes    = inputTypesRaw ? inputTypesRaw.split(',') : [];
    const injectKeysRaw = document.getElementById('import-inject-keys').value.trim();
    let   injectKeys    = {};
    if (injectKeysRaw) {
        try { injectKeys = JSON.parse(injectKeysRaw); } catch(e) { alert('Invalid JSON in File Inject Keys field.'); return; }
    }

    if (!name || !description) {
        alert('Please fill in the name and description.');
        return;
    }

    const resp = await fetch('{{ route("admin.workflows.comfy-import") }}', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        body:    JSON.stringify({ path, name, type, output_type: outputType, description, input_types: inputTypes, inject_keys: injectKeys }),
    });
    const data = await resp.json();

    if (data.success) {
        alert(`✓ ${data.message}`);
        location.reload();
    } else {
        alert(`✗ Import failed: ${data.error}`);
    }
}

// ── Paste JSON import ─────────────────────────────────────────────────────────
async function submitPasteImport() {
    const name        = document.getElementById('paste-name').value.trim();
    const type        = document.getElementById('paste-type').value;
    const outputType  = document.getElementById('paste-output-type').value;
    const description = document.getElementById('paste-description').value.trim();
    const json        = document.getElementById('paste-json').value.trim();
    const inputTypesRaw = document.getElementById('paste-input-types').value;
    const inputTypes    = inputTypesRaw ? inputTypesRaw.split(',') : [];
    const injectKeysRaw = document.getElementById('paste-inject-keys').value.trim();
    let   injectKeys    = {};
    if (injectKeysRaw) {
        try { injectKeys = JSON.parse(injectKeysRaw); } catch(e) { alert('Invalid JSON in File Inject Keys field.'); return; }
    }
    const resultEl = document.getElementById('paste-result');

    if (!name || !description || !json) {
        alert('Please fill in all required fields.');
        return;
    }

    // Client-side JSON validation — substitute numeric placeholders with dummy
    // values before parsing. Bare tokens like SEED are not valid JSON when
    // used as numeric field values. The original `json` (placeholders intact)
    // is what gets sent to the server and saved to the DB.
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
        body:    JSON.stringify({ name, type, output_type: outputType, description, workflow_json: json, input_types: inputTypes, inject_keys: injectKeys }),
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
    const name = row.querySelector('.font-medium.text-gray-800').textContent.trim();
    const desc = row.querySelector('.text-gray-400').textContent.trim();
    
    document.getElementById('edit-id').value = id;
    document.getElementById('edit-name').value = name;
    document.getElementById('edit-description').value = desc;
    document.getElementById('edit-result').classList.add('hidden');
    
    const typeCol = row.children[1];
    const editModal = document.getElementById('edit-modal');
    editModal.classList.remove('hidden');
}

function closeEditModal() {
    document.getElementById('edit-modal').classList.add('hidden');
}

document.getElementById('edit-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = document.getElementById('edit-id').value;
    const name = document.getElementById('edit-name').value.trim();
    const description = document.getElementById('edit-description').value.trim();
    const type = document.getElementById('edit-type').value;
    const outputType = document.getElementById('edit-output-type').value;
    const inputTypesRaw = document.getElementById('edit-input-types').value;
    const inputTypes = inputTypesRaw ? inputTypesRaw.split(',') : [];
    const injectKeysRaw = document.getElementById('edit-inject-keys').value.trim();
    let injectKeys = {};
    if (injectKeysRaw) {
        try { injectKeys = JSON.parse(injectKeysRaw); } catch(e) {
            alert('Invalid JSON in File Inject Keys field.'); return;
        }
    }
    
    const resp = await fetch(`/admin/workflows/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        body: JSON.stringify({ name, description, type, output_type: outputType, input_types: inputTypes, inject_keys: injectKeys }),
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

// ── Delete workflow ────────────────────────────────────────────────────────────
async function deleteWorkflow(id, name) {
    if (!confirm(`Are you sure you want to delete "${name}"? This action cannot be undone.`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('_method', 'DELETE');
    formData.append('_token', CSRF_TOKEN);
    
    const resp = await fetch(`/admin/workflows/${id}`, {
        method: 'POST',
        body: formData,
    });
    const data = await resp.json();
    
    if (data.success) {
        document.getElementById('wf-row-' + id).remove();
    } else {
        alert('Error deleting workflow: ' + (data.error || 'Unknown error'));
    }
}
</script>
@endsection