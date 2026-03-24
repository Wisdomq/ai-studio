@extends('layouts.studio')

@section('content')

{{-- Include components --}}
@include('studio.components.jobs_panel')
@include('studio.components.mood_board')

<div id="studio-app" class="min-h-screen flex flex-col">

    {{-- ── Header ───────────────────────────────────────────────────────────── --}}
    <header class="bg-white border-b border-forest-100 px-6 py-4 flex items-center justify-between sticky top-0 z-30 shadow-sm">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 bg-forest-500 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                </svg>
            </div>
            <span class="text-lg font-semibold text-gray-900 tracking-tight">AI Studio</span>
        </div>

        <div class="flex items-center gap-3">
            <div id="phase-indicator" class="text-xs font-medium text-forest-600 bg-forest-50 border border-forest-200 px-3 py-1 rounded-full">
                Ready to create
            </div>

            {{-- Mood Board trigger --}}
            <button id="btn-mood-board" onclick="toggleMoodBoard()"
                title="Creative Mood Board"
                class="relative p-2 text-gray-400 hover:text-forest-600 hover:bg-forest-50 rounded-xl transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/>
                </svg>
                <span id="mood-active-dot" class="hidden absolute top-1 right-1 w-2 h-2 bg-forest-500 rounded-full"></span>
            </button>

            {{-- Jobs panel trigger --}}
            <button onclick="toggleJobsPanel(true)"
                class="relative p-2 text-gray-400 hover:text-forest-600 hover:bg-forest-50 rounded-xl transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                </svg>
                <span id="jobs-badge" class="hidden absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] bg-forest-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center px-1"></span>
            </button>

            <a href="{{ route('studio.generations') }}"
               class="text-sm text-gray-500 hover:text-forest-600 transition font-medium">
                My Generations
            </a>
        </div>
    </header>

    {{-- Top progress bar --}}
    <div id="top-progress" class="hidden h-0.5 bg-forest-100">
        <div class="progress-bar w-full"></div>
    </div>

    {{-- ── Main ─────────────────────────────────────────────────────────────── --}}
    <main class="flex-1 max-w-2xl mx-auto w-full px-4 py-8 space-y-6">
        @include('studio.components.planning')
        @include('studio.components.refinement')
        @include('studio.components.execution')
    </main>
</div>

{{-- ── Step execution template ──────────────────────────────────────────── --}}
<template id="tpl-exec-step">
    <div class="exec-step bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
        <div class="px-5 py-4 flex items-center justify-between">
            <span class="step-name text-sm font-medium text-gray-800"></span>
            <span class="step-badge text-xs px-2.5 py-1 rounded-full font-medium"></span>
        </div>
        <div class="step-result hidden border-t border-gray-100">
            <div class="step-media-wrap bg-gray-50"></div>
            <div class="px-5 py-4 flex gap-3">
                <button class="btn-approve-step flex-1 py-2.5 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-medium transition flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    Approve
                </button>
                <button class="btn-reject-step flex-1 py-2.5 bg-white hover:bg-red-50 text-red-600 border border-red-200 rounded-xl text-sm font-medium transition">
                    Reject & Redo
                </button>
            </div>
        </div>
        <div class="step-error hidden border-t border-red-100 bg-red-50 px-5 py-4">
            <div class="flex items-start gap-2">
                <svg class="w-4 h-4 text-red-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <p class="text-xs font-semibold text-red-700 mb-1">Generation failed</p>
                    <p class="step-error-text text-xs text-red-600 font-mono leading-relaxed break-all"></p>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
// ── Constants ─────────────────────────────────────────────────────────────────
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

// ── State ─────────────────────────────────────────────────────────────────────
let conversationHistory  = [];
let currentPlan          = null;
let currentPlanId        = null;
let pollInterval         = null;
let jobsPollInterval     = null;
let stepRefinementState  = {};
let moodBoardSelections  = { colors: [], tags: [] };
let moodBoardApplied     = false;
let jobsPanelOpen        = false;

// ── Helpers ───────────────────────────────────────────────────────────────────
function setPhase(label) { document.getElementById('phase-indicator').textContent = label; }
function showProgress(show) { document.getElementById('top-progress').classList.toggle('hidden', !show); }

function setLoading(loading) {
    const btn   = document.getElementById('btn-send');
    const input = document.getElementById('user-input');
    btn.disabled = input.disabled = loading;
    btn.innerHTML = loading
        ? `<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>`
        : `<span>Send</span><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>`;
    showProgress(loading);
}

function resetToPlanning() {
    // Reset all state for a fresh generation
    conversationHistory = [];
    currentPlan = null;
    currentPlanId = null;
    stepRefinementState = {};

    document.getElementById('conversation').innerHTML = '';
    document.getElementById('plan-card').classList.add('hidden');
    document.getElementById('phase-planning').classList.remove('hidden');
    document.getElementById('phase-refinement').classList.add('hidden');
    document.getElementById('phase-execution').classList.add('hidden');
    document.getElementById('dispatch-actions').classList.add('hidden');
    document.getElementById('btn-continue-creating').classList.add('hidden');
    document.getElementById('refinement-steps').innerHTML = '';
    document.getElementById('execution-steps').innerHTML = '';
    document.getElementById('disambiguation-card')?.remove();

    setPhase('Ready to create');
    showProgress(false);

    document.getElementById('user-input').focus();
}

// ── Phase 1: Planning ─────────────────────────────────────────────────────────

document.getElementById('btn-send').addEventListener('click', sendMessage);
document.getElementById('user-input').addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});

async function sendMessage() {
    const input   = document.getElementById('user-input');
    const message = input.value.trim();
    if (!message) return;
    input.value = '';

    // Append mood board hint if applied
    let finalMessage = message;
    if (moodBoardApplied && moodBoardSelections.tags.length > 0) {
        const hint = buildMoodHint();
        if (hint) finalMessage += ` [Style: ${hint}]`;
    }

    appendMessage('user', message); // show clean message to user
    conversationHistory.push({ role: 'user', content: finalMessage }); // send with hint

    setLoading(true);
    const typingEl = appendTyping();
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 120000);
    let fullText = '';

    try {
        const resp = await fetch('{{ route("studio.planner") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
            body: JSON.stringify({ messages: conversationHistory }),
            signal: controller.signal,
        });

        typingEl.remove();
        const aiEl = appendMessage('assistant', '');
        const bubble = aiEl.querySelector('.bubble');
        bubble.classList.add('hidden');

        const reader = resp.body.getReader();
        const dec = new TextDecoder();
        let buffer = '', isDone = false;

        while (!isDone) {
            const { done, value } = await reader.read();
            if (done) break;
            buffer += dec.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop();

            for (const line of lines) {
                if (!line.startsWith('data: ')) continue;
                try {
                    const data = JSON.parse(line.slice(6));
                    if (data.type === 'chunk') {
                        fullText += data.content;
                        const display = stripPlanBlock(fullText);
                        bubble.textContent = display;
                        bubble.classList.toggle('hidden', !display);
                    }
                    if (data.type === 'error') {
                        bubble.textContent = '⚠ ' + data.message;
                        bubble.classList.remove('hidden');
                        bubble.classList.add('text-amber-700');
                    }
                    if (data.type === 'plan') { currentPlan = data.plan; renderPlanCard(data.plan); }
                    if (data.type === 'ambiguous') { renderDisambiguationCard(data.workflows); }
                    if (data.type === 'done') { isDone = true; break; }
                } catch(e) {}
            }
        }
        conversationHistory.push({ role: 'assistant', content: fullText });
    } catch (err) {
        typingEl?.remove();
        appendMessage('assistant', err.name === 'AbortError' ? 'Timed out. Please try again.' : 'Something went wrong.');
    } finally {
        clearTimeout(timeout);
        setLoading(false);
    }
}

function appendMessage(role, content) {
    const conv = document.getElementById('conversation');
    const el   = document.createElement('div');
    el.className = `fade-in flex ${role === 'user' ? 'msg-user justify-end' : 'msg-ai justify-start'}`;
    el.innerHTML = `<div class="bubble max-w-[85%] px-4 py-3 text-sm leading-relaxed">${content}</div>`;
    conv.appendChild(el);
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    return el;
}

function appendTyping() {
    const conv = document.getElementById('conversation');
    const el   = document.createElement('div');
    el.className = 'fade-in flex msg-ai justify-start';
    el.innerHTML = `<div class="bubble"><div class="typing-indicator"><span></span><span></span><span></span></div></div>`;
    conv.appendChild(el);
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    return el;
}

// ── Plan card ─────────────────────────────────────────────────────────────────

function renderPlanCard(plan) {
    document.getElementById('disambiguation-card')?.remove();
    const list = document.getElementById('plan-steps-list');
    list.innerHTML = '';

    const typeColors = { image:'bg-blue-50 text-blue-700 border-blue-200', video:'bg-purple-50 text-purple-700 border-purple-200', audio:'bg-amber-50 text-amber-700 border-amber-200' };

    plan.forEach((step, i) => {
        const col = typeColors[step.workflow_type] || 'bg-gray-50 text-gray-700 border-gray-200';
        const el  = document.createElement('div');
        el.className = 'flex items-start gap-3 py-2';
        el.innerHTML = `
            <div class="w-6 h-6 bg-forest-500 text-white rounded-full flex items-center justify-center text-xs font-bold shrink-0 mt-0.5">${i+1}</div>
            <div class="flex-1 min-w-0">
                <div class="text-sm font-medium text-gray-800">${step.purpose}</div>
                <div class="flex items-center gap-2 mt-1">
                    <span class="text-xs px-2 py-0.5 rounded-full border font-medium ${col}">${step.workflow_type}</span>
                    <span class="text-xs text-gray-400">ID: ${step.workflow_id}</span>
                </div>
            </div>`;
        list.appendChild(el);
    });
    document.getElementById('plan-card').classList.remove('hidden');
}

document.getElementById('btn-approve-plan').addEventListener('click', async () => {
    const userIntent = conversationHistory.find(m => m.role === 'user')?.content ?? '';
    showProgress(true);
    const resp = await fetch('{{ route("studio.plan.approve") }}', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        body: JSON.stringify({ user_intent: userIntent, steps: currentPlan }),
    });
    const data = await resp.json();
    currentPlanId = data.plan_id;
    showProgress(false);
    document.getElementById('plan-card').classList.add('hidden');
    document.getElementById('phase-planning').classList.add('hidden');
    startRefinementPhase(currentPlan, data.steps);
});

document.getElementById('btn-reject-plan').addEventListener('click', () => {
    document.getElementById('plan-card').classList.add('hidden');
    currentPlan = null;
    appendMessage('assistant', 'No problem — what would you like to change?');
});

// ── Disambiguation ────────────────────────────────────────────────────────────

function renderDisambiguationCard(workflows) {
    document.getElementById('disambiguation-card')?.remove();

    const typeColors = {
        image: { bg:'bg-blue-50', border:'border-blue-200', text:'text-blue-700', badge:'bg-blue-100 text-blue-700' },
        video: { bg:'bg-purple-50', border:'border-purple-200', text:'text-purple-700', badge:'bg-purple-100 text-purple-700' },
        audio: { bg:'bg-amber-50', border:'border-amber-200', text:'text-amber-700', badge:'bg-amber-100 text-amber-700' },
    };

    const card = document.createElement('div');
    card.id = 'disambiguation-card';
    card.className = 'slide-in bg-white border border-forest-200 rounded-2xl overflow-hidden shadow-sm';
    card.innerHTML = `
        <div class="bg-forest-50 border-b border-forest-100 px-5 py-3 flex items-center gap-2">
            <svg class="w-4 h-4 text-forest-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span class="text-sm font-semibold text-forest-700">Which workflow would you like?</span>
        </div>
        <div class="p-4 grid gap-3 ${workflows.length > 2 ? 'sm:grid-cols-3' : 'sm:grid-cols-2'}">
            ${workflows.map(w => {
                const c = typeColors[w.output_type] || typeColors.image;
                const inputs = w.input_types?.length ? w.input_types.join('+') : 'text';
                return `
                <button onclick="selectWorkflowFromDisambiguation(${w.id},'${w.name.replace(/'/g,"\\'")}','${w.output_type}')"
                    class="group text-left p-4 rounded-xl border-2 border-gray-200 hover:border-forest-400 bg-white hover:bg-forest-50 transition-all relative">
                    <div class="font-semibold text-sm text-gray-900 group-hover:text-forest-700 transition mb-1">${w.name}</div>
                    <div class="text-xs text-gray-500 leading-relaxed line-clamp-2 mb-2">${w.description}</div>
                    <div class="flex items-center gap-1.5">
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium ${c.badge}">${w.output_type}</span>
                        <span class="text-xs text-gray-400">${inputs}</span>
                    </div>
                    <svg class="w-4 h-4 text-forest-500 absolute top-3 right-3 opacity-0 group-hover:opacity-100 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>`;
            }).join('')}
        </div>
        <p class="text-xs text-gray-400 text-center pb-4">Click to select, or type your preference</p>`;

    const inputArea = document.getElementById('input-area');
    inputArea.parentNode.insertBefore(card, inputArea);
    card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function selectWorkflowFromDisambiguation(workflowId, workflowName, outputType) {
    document.getElementById('disambiguation-card')?.remove();
    const msg = `I'll use ${workflowName} (ID: ${workflowId})`;
    appendMessage('user', msg);
    conversationHistory.push({ role: 'user', content: msg });
    setLoading(true);
    const typingEl = appendTyping();
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 120000);
    let fullText = '';

    try {
        const resp = await fetch('{{ route("studio.planner") }}', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
            body: JSON.stringify({ messages: conversationHistory }), signal: controller.signal,
        });
        typingEl.remove();
        const aiEl = appendMessage('assistant', '');
        const bubble = aiEl.querySelector('.bubble');
        bubble.classList.add('hidden');
        const reader = resp.body.getReader();
        const dec = new TextDecoder();
        let buffer = '', isDone = false;

        while (!isDone) {
            const { done, value } = await reader.read();
            if (done) break;
            buffer += dec.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop();
            for (const line of lines) {
                if (!line.startsWith('data: ')) continue;
                try {
                    const data = JSON.parse(line.slice(6));
                    if (data.type === 'chunk') { fullText += data.content; const d = stripPlanBlock(fullText); bubble.textContent = d; bubble.classList.toggle('hidden', !d); }
                    if (data.type === 'plan') { currentPlan = data.plan; renderPlanCard(data.plan); }
                    if (data.type === 'ambiguous') { renderDisambiguationCard(data.workflows); }
                    if (data.type === 'done') { isDone = true; break; }
                } catch(e) {}
            }
        }
        conversationHistory.push({ role: 'assistant', content: fullText });
    } catch(err) {
        typingEl?.remove();
    } finally {
        clearTimeout(timeout);
        setLoading(false);
    }
}

// ── Phase 2: Refinement ───────────────────────────────────────────────────────

function startRefinementPhase(plan, steps) {
    document.getElementById('phase-refinement').classList.remove('hidden');
    setPhase('Refining prompts');

    // Extract the actual user intent from conversation history
    // — the first user message is always the original request
    const userIntent = conversationHistory
        .filter(m => m.role === 'user')
        .map(m => m.content)
        .join(' ')   // combine all user turns for full context
        .replace(/\[Style:[^\]]*\]/g, '') // strip any mood board hints
        .trim();

    plan.forEach((planStep, i) => {
        const stepId    = steps[i]?.id;
        const stepOrder = planStep.step_order;

        // Merge server-side data (input_types) into the plan step object.
        // currentPlan comes from the LLM and lacks workflow details;
        // the server response steps have the full workflow metadata.
        const enrichedStep = Object.assign({}, planStep, {
            input_types: steps[i]?.input_types ?? [],
        });

        // Build a rich seed message: "I want to create X. For this step: Y"
        const seedContent = userIntent
            ? `I want to create: ${userIntent}${plan.length > 1 ? `\n\nFor this specific step: ${planStep.purpose}` : ''}`
            : planStep.prompt_hint || planStep.purpose;

        stepRefinementState[stepOrder] = {
            messages:      [{ role: 'user', content: seedContent }],
            turnNumber:    1, confirmed: false, stepId,
            inputFilePath: null,
        };
        renderRefinementCard(enrichedStep, stepId);
        startRefinementStream(stepOrder);
    });
}

function renderRefinementCard(planStep, stepId) {
    const container = document.getElementById('refinement-steps');
    const card = document.createElement('div');
    card.id = `refine-step-${planStep.step_order}`;
    card.className = 'slide-in bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm';

    // Determine if this step needs a user-supplied input file
    const inputTypes = planStep.input_types ?? [];
    const needsFile  = inputTypes.length > 0;
    const fileLabel  = inputTypes.join(' / ');

    const fileUploadHtml = needsFile ? `
        <div class="file-upload-area border-t border-amber-100 bg-amber-50 px-5 py-4">
            <div class="flex items-center gap-2 mb-2">
                <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                </svg>
                <span class="text-xs font-semibold text-amber-700 uppercase tracking-wide">Input File Required</span>
                <span class="text-xs text-amber-600 font-normal">— ${fileLabel}</span>
            </div>
            <label class="file-upload-label flex items-center gap-3 cursor-pointer group">
                <div class="flex-1 flex items-center gap-2 px-4 py-2.5 bg-white border border-amber-200 rounded-xl hover:border-amber-400 transition text-sm text-gray-500 group-hover:text-gray-700">
                    <svg class="w-4 h-4 text-amber-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                    </svg>
                    <span class="file-upload-name">Choose ${fileLabel} file\u2026</span>
                </div>
                <input type="file" class="file-upload-input hidden" accept="${buildFileAccept(inputTypes)}">
            </label>
            <div class="file-upload-status hidden mt-2 flex items-center gap-2 text-xs text-forest-600">
                <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <span>Uploading\u2026</span>
            </div>
            <div class="file-upload-done hidden mt-2 flex items-center gap-2 text-xs text-forest-600">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="file-upload-done-text"></span>
            </div>
        </div>` : '';

    card.innerHTML = `
        <div class="bg-forest-50 border-b border-forest-100 px-5 py-3">
            <div class="text-sm font-semibold text-forest-800">${planStep.purpose}</div>
            <div class="text-xs text-forest-600 mt-0.5">Step ${planStep.step_order + 1} \xB7 ${planStep.workflow_type}</div>
        </div>
        ${fileUploadHtml}
        <div class="refine-status px-5 pt-4 pb-2">
            <div class="flex items-center gap-2 text-sm text-gray-500">
                <svg class="w-4 h-4 animate-spin text-forest-500" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <span class="refine-status-text">Crafting your prompt...</span>
            </div>
        </div>
        <div class="refine-chat px-5 pb-4 space-y-3 text-sm"></div>
        <div class="refine-input-area hidden px-5 pb-5">
            <div class="flex gap-2 border border-gray-200 rounded-xl overflow-hidden focus-within:border-forest-400 focus-within:shadow-sm transition-all">
                <textarea rows="1" placeholder="Reply to refine further..."
                    class="flex-1 px-4 py-3 text-sm bg-transparent border-none resize-none focus:ring-0 text-gray-800 placeholder-gray-400"></textarea>
                <button class="btn-refine-reply px-4 bg-forest-500 hover:bg-forest-600 text-white text-sm font-medium transition shrink-0">Send</button>
            </div>
            <p class="text-xs text-gray-400 mt-1.5 ml-1">Enter to send \xB7 Shift+Enter for new line</p>
        </div>
        <div class="confirmed-prompt hidden border-t border-forest-100 bg-forest-50 px-5 py-4">
            <div class="flex items-center gap-2 mb-2">
                <svg class="w-4 h-4 text-forest-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-xs font-semibold text-forest-700 uppercase tracking-wide">Suggested Prompt</span>
                <span class="text-xs text-gray-400 font-normal ml-1">\u2014 reply below to refine, or confirm to use</span>
                <button class="btn-copy-prompt ml-auto p-1 text-gray-400 hover:text-forest-600 transition rounded" title="Copy prompt">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                </button>
            </div>
            <div class="confirmed-text text-sm text-gray-800 bg-white border border-forest-200 rounded-xl px-4 py-3 leading-relaxed" contenteditable="false"></div>
            <div class="flex gap-2 mt-3">
                <button class="btn-confirm-prompt flex-1 py-2.5 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-medium transition">
                    \u2713 Confirm &amp; Use This Prompt
                </button>
                <button class="btn-edit-prompt px-4 py-2.5 bg-white hover:bg-gray-50 text-gray-600 border border-gray-200 rounded-xl text-sm font-medium transition">Edit</button>
            </div>
        </div>`;

    container.appendChild(card);

    // ── File upload listeners ─────────────────────────────────────────────────
    const fileInput = card.querySelector('.file-upload-input');
    if (fileInput) {
        // Track the uploaded storage path for this step
        stepRefinementState[planStep.step_order].inputFilePath = null;

        fileInput.addEventListener('change', async () => {
            const file = fileInput.files[0];
            if (!file) return;

            const nameEl    = card.querySelector('.file-upload-name');
            const statusEl  = card.querySelector('.file-upload-status');
            const doneEl    = card.querySelector('.file-upload-done');
            const doneText  = card.querySelector('.file-upload-done-text');

            nameEl.textContent = file.name;
            statusEl.classList.remove('hidden');
            doneEl.classList.add('hidden');

            // Detect media type from file mime
            const mediaType = file.type.startsWith('video/') ? 'video'
                            : file.type.startsWith('audio/') ? 'audio'
                            : 'image';

            const formData = new FormData();
            formData.append('file', file);
            formData.append('media_type', mediaType);
            formData.append('_token', CSRF_TOKEN);

            try {
                const resp = await fetch('/studio/upload', { method: 'POST', body: formData });
                const data = await resp.json();

                if (resp.ok && data.storage_path) {
                    stepRefinementState[planStep.step_order].inputFilePath = data.storage_path;
                    statusEl.classList.add('hidden');
                    doneEl.classList.remove('hidden');
                    doneText.textContent = `${file.name} ready`;
                } else {
                    statusEl.classList.add('hidden');
                    nameEl.textContent = `Upload failed: ${data.message ?? 'Unknown error'}`;
                }
            } catch (err) {
                statusEl.classList.add('hidden');
                nameEl.textContent = 'Upload error — try again';
            }
        });
    }

    // ── Copy prompt button ────────────────────────────────────────────────────
    card.querySelector('.btn-copy-prompt')?.addEventListener('click', () => {
        const text = card.querySelector('.confirmed-text').textContent.trim();
        navigator.clipboard.writeText(text).then(() => {
            const btn = card.querySelector('.btn-copy-prompt');
            btn.innerHTML = `<svg class="w-3.5 h-3.5 text-forest-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>`;
            setTimeout(() => {
                btn.innerHTML = `<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>`;
            }, 1500);
        });
    });
    const replyBtn = card.querySelector('.btn-refine-reply');
    const replyTa  = card.querySelector('.refine-input-area textarea');

    const submitReply = () => {
        const reply = replyTa.value.trim();
        if (!reply) return;
        replyTa.value = '';
        card.querySelector('.confirmed-prompt').classList.add('hidden');
        stepRefinementState[planStep.step_order].messages.push({ role:'user', content:reply });
        stepRefinementState[planStep.step_order].turnNumber++;
        startRefinementStream(planStep.step_order);
    };

    replyBtn.addEventListener('click', submitReply);
    replyTa.addEventListener('keydown', e => { if (e.key==='Enter'&&!e.shiftKey){e.preventDefault();submitReply();} });

    card.querySelector('.btn-edit-prompt').addEventListener('click', () => {
        const txt = card.querySelector('.confirmed-text');
        txt.contentEditable = txt.contentEditable==='true' ? 'false' : 'true';
        if (txt.contentEditable==='true') txt.focus();
        card.querySelector('.btn-edit-prompt').textContent = txt.contentEditable==='true' ? 'Done' : 'Edit';
    });

    card.querySelector('.btn-confirm-prompt').addEventListener('click', async () => {
        const finalPrompt = card.querySelector('.confirmed-text').textContent.trim();
        const btn = card.querySelector('.btn-confirm-prompt');
        btn.disabled = true; btn.textContent = 'Saving...';

        const body = { refined_prompt: finalPrompt };

        // Include uploaded input file path if one was provided
        const inputFilePath = stepRefinementState[planStep.step_order]?.inputFilePath;
        if (inputFilePath) body.input_file_path = inputFilePath;

        await fetch(`/studio/plan/${currentPlanId}/step/${planStep.step_order}/confirm`, {
            method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF_TOKEN},
            body: JSON.stringify(body),
        });

        btn.textContent = '\u2713 Confirmed';
        btn.className = btn.className.replace('bg-forest-500 hover:bg-forest-600','bg-gray-200 text-gray-500 cursor-default');
        card.classList.add('border-forest-300');
        card.querySelector('.refine-input-area').classList.add('hidden');
        stepRefinementState[planStep.step_order].confirmed = true;
        checkAllConfirmed();
    });
}

async function startRefinementStream(stepOrder) {
    const state    = stepRefinementState[stepOrder];
    const card     = document.getElementById(`refine-step-${stepOrder}`);
    const chat     = card.querySelector('.refine-chat');
    const statusEl = card.querySelector('.refine-status');
    const inputArea = card.querySelector('.refine-input-area');

    statusEl.classList.remove('hidden');
    inputArea.classList.add('hidden');

    const skeletonEl = document.createElement('div');
    skeletonEl.className = 'space-y-2 py-2';
    skeletonEl.innerHTML = `<div class="skeleton h-3 w-full"></div><div class="skeleton h-3 w-4/5"></div><div class="skeleton h-3 w-3/5"></div>`;
    chat.appendChild(skeletonEl);

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 120000);
    let fullText = '';

    try {
        const resp = await fetch('{{ route("studio.plan.refine-step") }}', {
            method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF_TOKEN},
            body: JSON.stringify({ plan_id:currentPlanId, step_order:stepOrder, messages:state.messages, turn_number:state.turnNumber }),
            signal: controller.signal,
        });

        skeletonEl.remove();
        statusEl.classList.add('hidden');

        const msgEl = document.createElement('div');
        msgEl.className = 'fade-in bg-gray-50 border border-gray-100 rounded-xl px-4 py-3 text-sm text-gray-700 leading-relaxed';
        chat.appendChild(msgEl);

        const reader = resp.body.getReader();
        const dec = new TextDecoder();
        let buffer = '', isDone = false;

        while (!isDone) {
            const { done, value } = await reader.read();
            if (done) break;
            buffer += dec.decode(value, { stream:true });
            const lines = buffer.split('\n'); buffer = lines.pop();
            for (const line of lines) {
                if (!line.startsWith('data: ')) continue;
                try {
                    const data = JSON.parse(line.slice(6));
                    if (data.type==='chunk') { fullText+=data.content; msgEl.textContent=stripApprovalBlock(fullText)||''; }
                    if (data.type==='error') {
                        msgEl.textContent = '⚠ ' + data.message;
                        msgEl.classList.add('text-amber-700', 'bg-amber-50', 'border-amber-200');
                    }
                    if (data.type==='approved') { showConfirmedPrompt(stepOrder, data.prompt); }
                    if (data.type==='done') { isDone=true; break; }
                } catch(e) {}
            }
        }

        state.messages.push({ role:'assistant', content:fullText });
        if (!state.confirmed) { inputArea.classList.remove('hidden'); inputArea.querySelector('textarea').focus(); }

    } catch(err) {
        skeletonEl.remove(); statusEl.classList.add('hidden');
        const errEl = document.createElement('div');
        errEl.className = 'text-sm text-red-500 px-1';
        errEl.textContent = err.name==='AbortError' ? 'Timed out.' : 'Error: '+err.message;
        chat.appendChild(errEl);
        inputArea.classList.remove('hidden');
    } finally {
        clearTimeout(timeout);
    }
}

function showConfirmedPrompt(stepOrder, prompt) {
    const card = document.getElementById(`refine-step-${stepOrder}`);
    card.querySelector('.confirmed-text').textContent = prompt;
    card.querySelector('.confirmed-prompt').classList.remove('hidden');
    card.querySelector('.refine-status')?.classList.add('hidden');
}

function checkAllConfirmed() {
    const allConfirmed = Object.values(stepRefinementState).every(s => s.confirmed);
    if (allConfirmed) {
        document.getElementById('dispatch-actions').classList.remove('hidden');
        document.getElementById('dispatch-actions').classList.add('fade-in');
    }
}

// ── Phase 3: Dispatch ─────────────────────────────────────────────────────────

document.getElementById('btn-dispatch').addEventListener('click', async () => {
    showProgress(true);
    setPhase('Generating...');
    const resp = await fetch(`/studio/plan/${currentPlanId}/dispatch`, {
        method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF_TOKEN},
    });
    const data = await resp.json();
    if (!data.success) { alert(data.error); showProgress(false); return; }

    document.getElementById('phase-refinement').classList.add('hidden');
    document.getElementById('phase-execution').classList.remove('hidden');
    renderExecutionSteps();
    startPolling();

    // Show "continue creating" button immediately
    setTimeout(() => document.getElementById('btn-continue-creating').classList.remove('hidden'), 3000);

    // Show mood board after 5 seconds
    setTimeout(() => toggleMoodBoard(true), 5000);
});

document.getElementById('btn-add-to-queue').addEventListener('click', async () => {
    const resp = await fetch(`/studio/plan/${currentPlanId}/queue`, {
        method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF_TOKEN},
    });
    const data = await resp.json();

    if (data.success) {
        if (data.auto_dispatched) {
            // Nothing running — started immediately
            document.getElementById('phase-refinement').classList.add('hidden');
            document.getElementById('phase-execution').classList.remove('hidden');
            renderExecutionSteps();
            startPolling();
        } else {
            // Added to backlog
            appendMessage('assistant', `Added to queue (position ${data.queue_position}). You can start a new generation now!`);
        }
        refreshJobsPanel();
        resetToPlanning();
    }
});

// ── Execution & polling ───────────────────────────────────────────────────────

function renderExecutionSteps() {
    const container = document.getElementById('execution-steps');
    container.innerHTML = '';
    currentPlan.forEach(planStep => {
        const tpl  = document.getElementById('tpl-exec-step');
        const node = tpl.content.cloneNode(true);
        const el   = node.querySelector('.exec-step');
        el.id      = `exec-step-${planStep.step_order}`;
        el.querySelector('.step-name').textContent = planStep.purpose;
        updateStepBadge(el, 'pending');
        container.appendChild(el);
    });
}

function startPolling() {
    pollInterval = setInterval(pollStatus, 4000);
    pollStatus();
}

async function pollStatus() {
    try {
        const resp = await fetch(`/studio/plan/${currentPlanId}/status`);
        const data = await resp.json();
        data.steps.forEach(step => updateExecStep(step));

        if (data.status === 'completed') {
            clearInterval(pollInterval);
            showProgress(false);
            setPhase('Complete! ✓');
            refreshJobsPanel();
            setTimeout(() => { window.location = `/studio/plan/${currentPlanId}/result`; }, 1500);
        }
        if (data.status === 'failed') {
            clearInterval(pollInterval);
            showProgress(false);
            setPhase('Generation failed');
            refreshJobsPanel();
        }
    } catch(e) {}
}

function updateExecStep(step) {
    const el = document.getElementById(`exec-step-${step.step_order}`);
    if (!el) return;
    updateStepBadge(el, step.status);

    if (step.status === 'awaiting_approval' && step.output_url) {
        const resultDiv = el.querySelector('.step-result');
        const mediaWrap = el.querySelector('.step-media-wrap');
        resultDiv.classList.remove('hidden');
        if (!mediaWrap.hasChildNodes()) {
            renderMedia(mediaWrap, step.output_url);
            el.querySelector('.btn-approve-step').addEventListener('click', () => approveStep(step.step_order));
            el.querySelector('.btn-reject-step').addEventListener('click', () => rejectStep(step.step_order));
        }
    }
    if (step.status === 'failed' && step.error_message) {
        const errDiv = el.querySelector('.step-error');
        el.querySelector('.step-error-text').textContent = step.error_message;
        errDiv.classList.remove('hidden');
    }
}

function renderMedia(container, url) {
    const ext = url.split('.').pop().toLowerCase();
    let el;
    if (['mp4','webm','mov'].includes(ext)) { el=document.createElement('video'); el.controls=true; el.className='w-full max-h-96 object-contain bg-black'; }
    else if (['mp3','wav','ogg','flac'].includes(ext)) { el=document.createElement('audio'); el.controls=true; el.className='w-full p-4'; }
    else { el=document.createElement('img'); el.alt='Generated output'; el.className='w-full max-h-96 object-contain'; }
    el.src = url;
    container.appendChild(el);
}

async function approveStep(stepOrder) {
    const resp = await fetch(`/studio/plan/${currentPlanId}/step/${stepOrder}/approve`, {
        method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF_TOKEN},
    });
    if (resp.ok) {
        const el = document.getElementById(`exec-step-${stepOrder}`);
        updateStepBadge(el, 'completed');
        el.querySelector('.step-result').classList.add('hidden');
    }
}

async function rejectStep(stepOrder) {
    const resp = await fetch(`/studio/plan/${currentPlanId}/step/${stepOrder}/reject`, {
        method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF_TOKEN},
    });
    if (!resp.ok) return;

    // Hide the output result in exec card
    const el = document.getElementById(`exec-step-${stepOrder}`);
    el.querySelector('.step-result').classList.add('hidden');
    updateStepBadge(el, 'pending');

    // Show re-refinement section
    document.getElementById('phase-execution').classList.add('hidden');
    document.getElementById('phase-refinement').classList.remove('hidden');

    // Find the plan step for re-refinement
    const planStep = currentPlan.find(s => s.step_order === stepOrder);
    if (!planStep) return;

    // Reset refinement state for this step
    stepRefinementState[stepOrder] = {
        messages:   [{ role:'user', content: planStep.prompt_hint || planStep.purpose }],
        turnNumber: 1,
        confirmed:  false,
        stepId:     stepRefinementState[stepOrder]?.stepId,
    };

    // Re-render just this step's refinement card
    const existing = document.getElementById(`refine-step-${stepOrder}`);
    if (existing) existing.remove();

    renderRefinementCard(planStep, stepRefinementState[stepOrder].stepId);
    startRefinementStream(stepOrder);

    // Override confirm button to re-dispatch after re-confirm
    setTimeout(() => {
        const card = document.getElementById(`refine-step-${stepOrder}`);
        if (!card) return;
        const btn = card.querySelector('.btn-confirm-prompt');
        const origClick = btn.onclick;
        btn.addEventListener('click', async () => {
            // After confirming, re-show execution and let job pick it up
            setTimeout(() => {
                document.getElementById('phase-refinement').classList.add('hidden');
                document.getElementById('phase-execution').classList.remove('hidden');
                updateStepBadge(document.getElementById(`exec-step-${stepOrder}`), 'pending');
            }, 500);
        }, { once: true });
    }, 100);
}

function updateStepBadge(el, status) {
    const badge = el.querySelector('.step-badge');
    const map = {
        pending:           ['Pending',         'badge-pending'],
        running:           ['Running…',        'badge-running'],
        awaiting_approval: ['Review Required', 'badge-approval'],
        completed:         ['Approved ✓',      'badge-completed'],
        failed:            ['Failed',          'badge-failed'],
    };
    const [label, cls] = map[status] ?? ['Unknown','badge-pending'];
    badge.textContent = label;
    badge.className = `step-badge text-xs px-2.5 py-1 rounded-full font-medium ${cls}`;
}

// ── Jobs Panel ────────────────────────────────────────────────────────────────

function toggleJobsPanel(open) {
    const panel   = document.getElementById('jobs-panel');
    const overlay = document.getElementById('jobs-panel-overlay');
    jobsPanelOpen = open ?? !jobsPanelOpen;

    if (jobsPanelOpen) {
        panel.style.transform = 'translateX(0)';
        overlay.classList.remove('hidden');
        refreshJobsPanel();
        if (!jobsPollInterval) jobsPollInterval = setInterval(refreshJobsPanel, 5000);
    } else {
        panel.style.transform = 'translateX(100%)';
        overlay.classList.add('hidden');
        clearInterval(jobsPollInterval);
        jobsPollInterval = null;
    }
}

async function refreshJobsPanel() {
    try {
        const [jobsResp, statusResp] = await Promise.all([
            fetch('{{ route("studio.jobs") }}'),
            fetch('{{ route("studio.queue-status") }}'),
        ]);
        const jobs   = await jobsResp.json();
        const status = await statusResp.json();

        renderJobsList(jobs.plans);
        updateQueueSummary(status);
    } catch(e) {}
}

function renderJobsList(plans) {
    const list = document.getElementById('jobs-list');
    if (!plans || plans.length === 0) {
        list.innerHTML = `<div class="text-center text-gray-400 text-sm py-8"><svg class="w-8 h-8 mx-auto mb-2 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>No jobs yet</div>`;
        return;
    }

    const statusConfig = {
        pending:   { label:'Pending',    cls:'badge-pending'  },
        queued:    { label:'Queued',     cls:'badge-queued'   },
        running:   { label:'Running',    cls:'badge-running'  },
        completed: { label:'Done ✓',     cls:'badge-completed'},
        failed:    { label:'Failed',     cls:'badge-failed'   },
    };

    list.innerHTML = plans.map(plan => {
        const sc       = statusConfig[plan.status] || statusConfig.pending;
        const hasOutput = plan.steps?.some(s => s.output_url);
        const isAwaiting = plan.steps?.some(s => s.status === 'awaiting_approval');
        const firstOutput = plan.steps?.find(s => s.output_url)?.output_url;

        return `
        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden hover:border-forest-300 transition">
            ${firstOutput ? `
            <div class="h-24 bg-gray-100 overflow-hidden">
                <img src="${firstOutput}" class="w-full h-full object-cover" onerror="this.parentElement.style.display='none'">
            </div>` : `<div class="h-1 ${plan.status==='running'?'bg-forest-200':plan.status==='queued'?'bg-amber-200':'bg-gray-100'}">
                ${plan.status==='running'?'<div class="progress-bar h-full"></div>':''}
            </div>`}
            <div class="px-3 py-2.5">
                <div class="flex items-start justify-between gap-2 mb-1.5">
                    <div class="text-xs font-medium text-gray-800 line-clamp-1 flex-1">${plan.user_intent || 'Untitled generation'}</div>
                    <span class="text-xs px-2 py-0.5 rounded-full font-medium shrink-0 ${sc.cls}">${sc.label}</span>
                </div>
                <div class="flex items-center gap-2">
                    ${plan.status==='running' ? `<div class="text-xs text-forest-600 flex items-center gap-1"><svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>Generating...</div>` : ''}
                    ${isAwaiting ? `<a href="/studio/plan/${plan.plan_id}/status" class="text-xs text-blue-600 hover:text-blue-700 font-medium">Review result →</a>` : ''}
                    ${plan.status==='completed' ? `<a href="/studio/plan/${plan.plan_id}/result" class="text-xs text-forest-600 hover:text-forest-700 font-medium">View result →</a>` : ''}
                    ${plan.status==='queued' ? `<span class="text-xs text-amber-600">Queue #${plan.queue_position}</span>` : ''}
                </div>
            </div>
        </div>`;
    }).join('');
}

function updateQueueSummary(status) {
    const badge   = document.getElementById('jobs-badge');
    const pBadge  = document.getElementById('panel-badge');
    const summary = document.getElementById('queue-summary');
    const runBtn  = document.getElementById('btn-run-queue');

    const total = status.total_active || 0;

    if (total > 0) {
        badge.textContent = total; badge.classList.remove('hidden');
        pBadge.textContent = total; pBadge.classList.remove('hidden');
    } else {
        badge.classList.add('hidden');
        pBadge.classList.add('hidden');
    }

    if (status.running || status.queued || status.awaiting) {
        summary.classList.remove('hidden');
        document.getElementById('qs-running').textContent  = status.running  ? `${status.running} running`   : '';
        document.getElementById('qs-queued').textContent   = status.queued   ? `${status.queued} queued`     : '';
        document.getElementById('qs-awaiting').textContent = status.awaiting ? `${status.awaiting} awaiting` : '';
    } else {
        summary.classList.add('hidden');
    }

    // Show "Run Queue" button only when queued and nothing running
    runBtn.classList.toggle('hidden', !(status.queued > 0 && status.running === 0));
}

async function runNextInQueue() {
    const resp = await fetch('{{ route("studio.queue.run-next") }}', {
        method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF_TOKEN},
    });
    const data = await resp.json();
    if (data.success) refreshJobsPanel();
    else alert(data.error);
}

// ── Mood Board ────────────────────────────────────────────────────────────────

function toggleMoodBoard(forceOpen) {
    const overlay = document.getElementById('mood-board-overlay');
    const isHidden = overlay.classList.contains('hidden');
    const show = forceOpen !== undefined ? forceOpen : isHidden;
    overlay.classList.toggle('hidden', !show);
}

function closeMoodBoard() { toggleMoodBoard(false); }

function toggleMoodColor(btn) {
    const color = btn.dataset.color;
    const name  = btn.dataset.name;
    const idx   = moodBoardSelections.colors.findIndex(c => c.color === color);
    if (idx >= 0) {
        moodBoardSelections.colors.splice(idx, 1);
        btn.style.outline = '';
        btn.style.transform = '';
    } else {
        moodBoardSelections.colors.push({ color, name });
        btn.style.outline = '3px solid #2da62d';
        btn.style.outlineOffset = '2px';
        btn.style.transform = 'scale(1.15)';
    }
    updateMoodPreview();
}

function toggleMoodTag(btn) {
    const tag = btn.dataset.tag;
    const idx = moodBoardSelections.tags.indexOf(tag);
    if (idx >= 0) {
        moodBoardSelections.tags.splice(idx, 1);
        btn.classList.remove('selected', 'bg-forest-500', 'text-white', 'border-forest-500');
    } else {
        moodBoardSelections.tags.push(tag);
        btn.classList.add('selected', 'bg-forest-500', 'text-white', 'border-forest-500');
    }
    updateMoodPreview();
}

function buildMoodHint() {
    const parts = [];
    if (moodBoardSelections.colors.length) parts.push(moodBoardSelections.colors.map(c => c.name).join(', ') + ' tones');
    if (moodBoardSelections.tags.length) parts.push(moodBoardSelections.tags.join(', '));
    return parts.join(', ');
}

function updateMoodPreview() {
    const hint    = buildMoodHint();
    const preview = document.getElementById('mood-preview');
    const text    = document.getElementById('mood-preview-text');
    if (hint) {
        text.textContent = hint;
        preview.classList.remove('hidden');
    } else {
        preview.classList.add('hidden');
    }
}

function applyMoodBoard() {
    moodBoardApplied = true;
    const dot = document.getElementById('mood-active-dot');
    dot.classList.remove('hidden');
    closeMoodBoard();
    // Save to plan if we have one
    if (currentPlanId) {
        fetch(`/studio/plan/${currentPlanId}/mood-board`, {
            method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF_TOKEN},
            body: JSON.stringify({ mood_board: moodBoardSelections }),
        });
    }
}

function clearMoodBoard() {
    moodBoardSelections = { colors:[], tags:[] };
    moodBoardApplied    = false;
    document.getElementById('mood-active-dot').classList.add('hidden');
    document.querySelectorAll('.mood-swatch').forEach(b => { b.style.outline=''; b.style.transform=''; });
    document.querySelectorAll('.mood-tag').forEach(b => b.classList.remove('selected','bg-forest-500','text-white','border-forest-500'));
    updateMoodPreview();
}

// ── Prompt export ─────────────────────────────────────────────────────────────

function exportPrompts() {
    const lines = [];
    const intent = conversationHistory.find(m => m.role === 'user')?.content ?? 'Generation';
    lines.push(`AI Studio — Prompt Export`);
    lines.push(`Intent: ${intent}`);
    lines.push(`Exported: ${new Date().toLocaleString()}`);
    lines.push('');

    Object.entries(stepRefinementState).forEach(([order, state]) => {
        const card = document.getElementById(`refine-step-${order}`);
        const prompt = card?.querySelector('.confirmed-text')?.textContent?.trim();
        if (prompt) {
            const purpose = card.querySelector('.text-forest-800')?.textContent?.trim() ?? `Step ${parseInt(order) + 1}`;
            lines.push(`--- Step ${parseInt(order) + 1}: ${purpose} ---`);
            lines.push(prompt);
            lines.push('');
        }
    });

    if (lines.length <= 4) {
        alert('No confirmed prompts to export yet.');
        return;
    }

    const blob = new Blob([lines.join('\n')], { type: 'text/plain' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `prompts-${Date.now()}.txt`;
    a.click();
    URL.revokeObjectURL(url);
}

// ── File upload helpers ───────────────────────────────────────────────────────

/**
 * Build an HTML accept attribute string from an array of media type strings.
 * e.g. ['image','audio'] → "image/*,audio/*"
 */
function buildFileAccept(inputTypes) {
    return inputTypes.map(t => {
        if (t === 'image') return 'image/*';
        if (t === 'video') return 'video/*';
        if (t === 'audio') return 'audio/*';
        return '*/*';
    }).join(',');
}

// ── Text strip helpers ────────────────────────────────────────────────────────

function stripPlanBlock(text) {
    text = text.replace(/\[PLAN\][\s\S]*/g, '');
    text = text.replace(/\[\/PLAN\]/g, '');
    text = text.replace(/```[\s\S]*?```/g, '');
    return text.trim();
}

function stripApprovalBlock(text) {
    text = text.replace(/\[READY_FOR_APPROVAL\][\s\S]*?(\[\/READY_FOR_APPROVAL\]|$)/g, '');
    text = text.replace(/\n?APPROVED:.*$/im, '');
    return text.trim();
}

// ── Init ──────────────────────────────────────────────────────────────────────

// Start jobs panel background polling
setInterval(async () => {
    try {
        const resp = await fetch('{{ route("studio.queue-status") }}');
        const data = await resp.json();
        updateQueueSummary(data);
    } catch(e) {}
}, 10000);
</script>

@endsection