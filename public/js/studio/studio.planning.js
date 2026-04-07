// ── AI Studio — Phase 1: Planning ────────────────────────────────────────────

let attachedFiles = [];

document.getElementById('btn-send').addEventListener('click', sendMessage);
document.getElementById('user-input').addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});

document.getElementById('file-input').addEventListener('change', handleFileSelect);

async function handleFileSelect(e) {
    const files = Array.from(e.target.files);
    if (!files.length) return;

    for (const file of files) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('media_type', getMediaType(file.type));

        try {
            const resp = await fetch(STUDIO_ROUTES.upload, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': CSRF_TOKEN },
                body: formData,
            });
            const data = await resp.json();

            if (data.storage_path) {
                attachedFiles.push({
                    id: Date.now() + Math.random(),
                    storage_path: data.storage_path,
                    url: data.url,
                    media_type: data.media_type,
                    name: file.name,
                });
                renderAttachedFiles();
            }
        } catch (err) {
            console.error('File upload failed:', err);
            appendMessage('assistant', '⚠ Failed to upload file. Please try again.');
        }
    }
    e.target.value = '';
}

function getMediaType(mimeType) {
    if (mimeType.startsWith('image/')) return 'image';
    if (mimeType.startsWith('video/')) return 'video';
    if (mimeType.startsWith('audio/')) return 'audio';
    return 'image';
}

function renderAttachedFiles() {
    const container = document.getElementById('attached-files');
    if (attachedFiles.length === 0) {
        container.classList.add('hidden');
        container.innerHTML = '';
        return;
    }

    container.classList.remove('hidden');
    container.innerHTML = `
        <div class="flex flex-wrap gap-2 mb-2">
            ${attachedFiles.map(f => `
                <div class="attached-file group relative flex items-center gap-2 bg-gray-100 rounded-lg px-2 py-1.5" data-id="${f.id}">
                    ${f.media_type === 'image' 
                        ? `<img src="${f.url}" class="w-8 h-8 object-cover rounded">`
                        : `<div class="w-8 h-8 flex items-center justify-center rounded ${f.media_type === 'video' ? 'bg-purple-100' : 'bg-amber-100'}">
                            <svg class="w-4 h-4 ${f.media_type === 'video' ? 'text-purple-600' : 'text-amber-600'}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                ${f.media_type === 'video' 
                                    ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>'
                                    : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"/>'}
                            </svg>
                           </div>`
                    }
                    <span class="text-xs text-gray-600 max-w-[80px] truncate">${f.name}</span>
                    <button onclick="removeAttachedFile('${f.id}')" class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition text-[10px]">×</button>
                </div>
            `).join('')}
        </div>
    `;
}

function removeAttachedFile(id) {
    attachedFiles = attachedFiles.filter(f => f.id != id);
    renderAttachedFiles();
}

async function sendMessage() {
    const input   = document.getElementById('user-input');
    const message = input.value.trim();
    if (!message && attachedFiles.length === 0) return;
    
    const currentFiles = [...attachedFiles];
    input.value = '';

    let finalMessage = message;
    if (moodBoardApplied && moodBoardSelections.tags.length > 0) {
        const hint = buildMoodHint();
        if (hint) finalMessage += ` [Style: ${hint}]`;
    }

    if (currentFiles.length > 0) {
        const fileDesc = currentFiles.map(f => `INPUT:${f.media_type}:${f.name}`).join(' ');
        finalMessage += ` ${fileDesc}`;
    }

    appendMessage('user', message, currentFiles);
    conversationHistory.push({ role: 'user', content: finalMessage, files: currentFiles });

    attachedFiles = [];
    renderAttachedFiles();

    setLoading(true);
    const typingEl    = appendTyping();
    const controller  = new AbortController();
    const timeout     = setTimeout(() => controller.abort(), 120000);
    let fullText      = '';

    try {
        const resp = await fetch(STUDIO_ROUTES.planner, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
            body:    JSON.stringify({ messages: conversationHistory }),
            signal:  controller.signal,
        });

        typingEl.remove();
        const aiEl  = appendMessage('assistant', '');
        const bubble = aiEl.querySelector('.bubble');
        bubble.classList.add('hidden');

        fullText = await readPlannerStream(resp, bubble);
        conversationHistory.push({ role: 'assistant', content: fullText });

    } catch (err) {
        typingEl?.remove();
        appendMessage('assistant', err.name === 'AbortError'
            ? 'Timed out. Please try again.'
            : 'Something went wrong.');
    } finally {
        clearTimeout(timeout);
        setLoading(false);
    }
}

// Strips signal tokens and LLM reasoning bleed-through from text before
// it is shown in a chat bubble. Mirrors the PHP stripSignal() logic so
// the display is clean even during streaming before the done event fires.
function stripSignalsFromDisplay(text) {
    // Remove signal lines
    text = text.replace(/^READY:[\d,\s]+.*$/gim, '');
    text = text.replace(/^AMBIGUOUS:[\d,\s]+.*$/gim, '');
    text = text.replace(/^CREATE_WORKFLOW:.*$/gim, '');
    // Remove echoed decision-tree step headers
    text = text.replace(/^STEP\s+\d+[^:\n]*:.*$/gim, '');
    text = text.replace(/^(?:\d+\.\s+)?(?:SINGLE WORKFLOW|MULTI.WORKFLOW|BUILD A NEW WORKFLOW|NOTHING FITS)\b.*$/gim, '');
    // Remove markdown bold/heading artefacts
    text = text.replace(/\*{2,}[^*\n]+\*{2,}/g, '');
    text = text.replace(/^#{1,3}\s+.+$/gim, '');
    // Collapse whitespace
    return text.replace(/\n{3,}/g, '\n\n').trim();
}

async function selectWorkflowFromDisambiguation(workflowId, workflowName, outputType) {
    document.getElementById('disambiguation-card')?.remove();
    const msg = `I'll use ${workflowName} (ID: ${workflowId})`;
    appendMessage('user', msg);
    conversationHistory.push({ role: 'user', content: msg });

    setLoading(true);
    const typingEl   = appendTyping();
    const controller = new AbortController();
    const timeout    = setTimeout(() => controller.abort(), 120000);
    let fullText     = '';

    try {
        const resp = await fetch(STUDIO_ROUTES.planner, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
            body:    JSON.stringify({ messages: conversationHistory }),
            signal:  controller.signal,
        });
        typingEl.remove();
        const aiEl   = appendMessage('assistant', '');
        const bubble = aiEl.querySelector('.bubble');
        bubble.classList.add('hidden');

        fullText = await readPlannerStream(resp, bubble);
        conversationHistory.push({ role: 'assistant', content: fullText });

    } catch(err) {
        typingEl?.remove();
    } finally {
        clearTimeout(timeout);
        setLoading(false);
    }
}

// Shared SSE reader for both sendMessage and selectWorkflowFromDisambiguation
async function readPlannerStream(resp, bubble) {
    const reader  = resp.body.getReader();
    const dec     = new TextDecoder();
    let buffer    = '', isDone = false, fullText = '';

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
                    // Strip signal tokens + plan blocks before displaying in the bubble.
                    // The LLM sometimes emits the signal inline before PHP has a chance
                    // to intercept it, so we must also scrub it client-side.
                    const display = stripSignalsFromDisplay(stripPlanBlock(fullText));
                    bubble.textContent = display;
                    bubble.classList.toggle('hidden', !display.trim());
                }
                if (data.type === 'error') {
                    bubble.textContent = '⚠ ' + data.message;
                    bubble.classList.remove('hidden');
                    bubble.classList.add('text-amber-700');
                }
                if (data.type === 'workflow_proposed') { handleWorkflowProposed(data); }
                if (data.type === 'plan')              { currentPlan = data.plan; renderPlanCards(data.plan); }
                if (data.type === 'ambiguous')         { renderDisambiguationCard(data.workflows); }
                if (data.type === 'no_signal')         { handleNoSignal(data); }
                if (data.type === 'done')              { isDone = true; break; }
            } catch(e) {}
        }
    }
    return fullText;
}

// ── Message rendering ─────────────────────────────────────────────────────────

function formatContent(text) {
    if (!text) return '';
    
    let formatted = text;
    
    formatted = formatted.replace(/^[-*]\s+(.+)$/gm, '<li>$1</li>');
    
    formatted = formatted.replace(/(<li>.*?<\/li>)+/gs, function(match) {
        return '<ul class="list-disc pl-5 my-2 space-y-1">' + match + '</ul>';
    });
    
    formatted = formatted.replace(/\n\n+/g, '</p><p class="my-2">');
    formatted = formatted.replace(/\n/g, '<br>');
    
    return `<p class="my-0">${formatted}</p>`;
}

function formatUserFiles(files) {
    if (!files || files.length === 0) return '';
    
    const thumbnails = files.map(f => {
        const thumbUrl = `/storage/${f.storage_path}`;
        return `
            <div class="relative group inline-block mt-2 mr-2">
                <img src="${thumbUrl}" 
                     alt="${f.name}" 
                     class="w-20 h-20 object-cover rounded-lg border border-gray-200 shadow-sm"
                     onerror="this.style.display='none'">
                <div class="absolute bottom-0 left-0 right-0 bg-black/60 text-white text-[10px] px-1 py-0.5 rounded-b-lg truncate">
                    ${f.name}
                </div>
            </div>
        `;
    }).join('');
    
    return `<div class="flex flex-wrap mt-2">${thumbnails}</div>`;
}

function appendMessage(role, content, files = null) {
    const conv = document.getElementById('conversation');
    const el   = document.createElement('div');
    
    const formattedContent = role === 'assistant' ? formatContent(content) : content.replace(/INPUT:[^:]+:[^\s]+/g, '').trim();
    const fileThumbnails = (role === 'user' && files) ? formatUserFiles(files) : '';
    
    el.className = `fade-in flex ${role === 'user' ? 'msg-user justify-end' : 'msg-ai justify-start'}`;
    el.innerHTML = `
        <div class="bubble max-w-[85%] px-4 py-3 text-sm leading-relaxed">
            ${formattedContent}
            ${fileThumbnails}
        </div>`;
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

// ── Plan cards ────────────────────────────────────────────────────────────────
//
// Single-step: renders the existing single plan-card (unchanged UX).
// Multi-step:  renders one individual approval card per step so the user
//              can approve/reject each workflow independently.
//              Refinement only starts once ALL cards have been approved.

// Tracks per-step approval state for multi-step flows.
// Shape: { [step_order]: { approved: bool, rejected: bool } }
let planCardApprovals = {};

function renderPlanCards(plan) {
    document.getElementById('disambiguation-card')?.remove();

    if (plan.length === 1) {
        // ── Single step: use the original plan-card element ──────────────────
        renderSinglePlanCard(plan);
    } else {
        // ── Multi-step: one card per step ────────────────────────────────────
        renderMultiPlanCards(plan);
    }
}

// ── Single-step: original plan card ──────────────────────────────────────────

function renderSinglePlanCard(plan) {
    const list = document.getElementById('plan-steps-list');
    list.innerHTML = '';

    const typeColors = {
        image: 'bg-blue-50 text-blue-700 border-blue-200',
        video: 'bg-purple-50 text-purple-700 border-purple-200',
        audio: 'bg-amber-50 text-amber-700 border-amber-200',
    };

    plan.forEach((step, i) => {
        const col           = typeColors[step.workflow_type] || 'bg-gray-50 text-gray-700 border-gray-200';
        const hasDependency = step.depends_on && step.depends_on.length > 0;
        const el            = document.createElement('div');
        el.className        = 'flex items-start gap-3 py-2';
        el.innerHTML = `
            <div class="flex flex-col items-center">
                <div class="w-6 h-6 bg-forest-500 text-white rounded-full flex items-center justify-center text-xs font-bold shrink-0">${i + 1}</div>
                ${i < plan.length - 1 ? '<div class="w-0.5 h-6 bg-forest-200 mt-1"></div>' : ''}
            </div>
            <div class="flex-1 min-w-0 pb-2 ${i < plan.length - 1 ? 'border-b border-dashed border-gray-200' : ''}">
                <div class="text-sm font-medium text-gray-800">${step.purpose}</div>
                <div class="flex items-center gap-2 mt-1 flex-wrap">
                    <span class="text-xs px-2 py-0.5 rounded-full border font-medium ${col}">${step.workflow_type}</span>
                    ${hasDependency ? '<span class="text-xs text-forest-600">← from previous step</span>' : ''}
                </div>
            </div>`;
        list.appendChild(el);
    });

    document.getElementById('plan-card').classList.remove('hidden');
}

// Single-step approve / reject (wired to the existing plan-card buttons)
document.getElementById('btn-approve-plan').addEventListener('click', async () => {
    if (!currentPlan || currentPlan.length !== 1) return; // multi-step uses its own buttons
    await submitApprovedPlan(currentPlan);
});

document.getElementById('btn-reject-plan').addEventListener('click', () => {
    document.getElementById('plan-card').classList.add('hidden');
    currentPlan = null;
    appendMessage('assistant', 'No problem — what would you like to change?');
});

// ── Multi-step: individual per-workflow cards ─────────────────────────────────

function renderMultiPlanCards(plan) {
    // Hide the old shared plan-card (not used for multi-step)
    document.getElementById('plan-card').classList.add('hidden');

    // Remove any stale multi-step cards from a prior attempt
    document.getElementById('multi-plan-cards')?.remove();

    planCardApprovals = {};
    plan.forEach(step => { planCardApprovals[step.step_order] = { approved: false, rejected: false }; });

    const typeColors = {
        image: { border: 'border-blue-200',   header: 'bg-blue-50 border-blue-100',   badge: 'bg-blue-100 text-blue-700 border-blue-200' },
        video: { border: 'border-purple-200', header: 'bg-purple-50 border-purple-100', badge: 'bg-purple-100 text-purple-700 border-purple-200' },
        audio: { border: 'border-amber-200',  header: 'bg-amber-50 border-amber-100',  badge: 'bg-amber-100 text-amber-700 border-amber-200' },
    };

    const wrapper = document.createElement('div');
    wrapper.id        = 'multi-plan-cards';
    wrapper.className = 'space-y-3 slide-in';

    // Header showing total steps
    const headerEl = document.createElement('div');
    headerEl.className = 'flex items-center gap-2';
    headerEl.innerHTML = `
        <div class="h-px flex-1 bg-forest-100"></div>
        <span class="text-xs font-semibold text-forest-600 uppercase tracking-widest px-2">
            ${plan.length}-Step Generation Plan — approve each step
        </span>
        <div class="h-px flex-1 bg-forest-100"></div>`;
    wrapper.appendChild(headerEl);

    plan.forEach((step, i) => {
        const c             = typeColors[step.workflow_type] || typeColors.image;
        const hasDependency = step.depends_on && step.depends_on.length > 0;
        const dependsOnStep = hasDependency ? step.depends_on[step.depends_on.length - 1] + 1 : null;

        const card = document.createElement('div');
        card.id        = `plan-card-step-${step.step_order}`;
        card.className = `slide-in bg-white border ${c.border} rounded-2xl overflow-hidden shadow-sm`;
        card.innerHTML = `
            <div class="bg-forest-50 border-b border-forest-100 px-5 py-3 flex items-center gap-2">
                <div class="w-5 h-5 bg-forest-500 text-white rounded-full flex items-center justify-center text-xs font-bold shrink-0">${i + 1}</div>
                <svg class="w-4 h-4 text-forest-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
                <span class="text-sm font-semibold text-forest-700">Step ${i + 1} of ${plan.length}</span>
            </div>
            <div class="px-5 py-4">
                <div class="text-sm font-medium text-gray-800 mb-2">${step.purpose}</div>
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-xs px-2 py-0.5 rounded-full border font-medium ${c.badge}">${step.workflow_type}</span>
                    ${hasDependency ? '<span class="text-xs text-forest-600 flex items-center gap-1"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4"/></svg>uses output from step ' + dependsOnStep + '</span>' : ''}
                </div>
            </div>
            <div class="px-5 pb-5 flex gap-3">
                <button class="btn-approve-step-card flex-1 px-4 py-2.5 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-medium transition shadow-sm flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                    </svg>
                    Approve Step ${i + 1}
                </button>
                <button class="btn-reject-step-card px-4 py-2.5 bg-white hover:bg-gray-50 text-gray-600 border border-gray-200 rounded-xl text-sm font-medium transition">
                    Skip
                </button>
            </div>`;

        // Approve button
        card.querySelector('.btn-approve-step-card').addEventListener('click', () => {
            markStepCardApproved(step.step_order, card);
        });

        // Reject/skip button — removes this step from the plan
        card.querySelector('.btn-reject-step-card').addEventListener('click', () => {
            markStepCardRejected(step.step_order, card);
        });

        wrapper.appendChild(card);
    });

    // Insert above the input area
    const inputArea = document.getElementById('input-area');
    inputArea.parentNode.insertBefore(wrapper, inputArea);
    wrapper.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function markStepCardApproved(stepOrder, card) {
    planCardApprovals[stepOrder].approved = true;

    // Replace card buttons with a green confirmed state
    card.querySelector('.px-5.pb-5').innerHTML = `
        <div class="flex items-center gap-2 text-forest-600 text-sm font-medium py-1">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
            </svg>
            Approved — ready to refine
        </div>`;
    card.classList.remove('border-blue-200', 'border-purple-200', 'border-amber-200');
    card.classList.add('border-forest-300');

    checkAllStepCardsDecided();
}

function markStepCardRejected(stepOrder, card) {
    planCardApprovals[stepOrder].rejected = true;

    // Grey out the card
    card.querySelector('.px-5.pb-5').innerHTML = `
        <div class="flex items-center gap-2 text-gray-400 text-sm py-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
            Skipped
        </div>`;
    card.classList.add('opacity-50');

    checkAllStepCardsDecided();
}

function checkAllStepCardsDecided() {
    const allDecided = Object.values(planCardApprovals).every(s => s.approved || s.rejected);
    if (!allDecided) return;

    // Build the approved sub-plan (only approved steps, re-indexed)
    const approvedSteps = currentPlan
        .filter(step => planCardApprovals[step.step_order]?.approved)
        .map((step, newIndex) => ({
            ...step,
            step_order: newIndex,
            // Re-wire depends_on to new indices
            depends_on: newIndex > 0 ? [newIndex - 1] : [],
        }));

    if (approvedSteps.length === 0) {
        // All skipped — clean up and let user try again
        document.getElementById('multi-plan-cards')?.remove();
        currentPlan = null;
        appendMessage('assistant', 'All steps skipped — no problem! Tell me what you\'d like to create and I\'ll build a new plan.');
        return;
    }

    submitApprovedPlan(approvedSteps);
}

// ── Shared plan submission (used by both single and multi-step flows) ─────────

async function submitApprovedPlan(approvedSteps) {
    const userMessage = conversationHistory.find(m => m.role === 'user');
    const userIntent  = userMessage?.content ?? '';
    const files       = userMessage?.files ?? [];

    showProgress(true);

    const resp = await fetch(STUDIO_ROUTES.planApprove, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        body:    JSON.stringify({ user_intent: userIntent, steps: approvedSteps, files }),
    });
    const data = await resp.json();

    currentPlanId = data.plan_id;
    // Update currentPlan to the approved (possibly trimmed) step set
    currentPlan = approvedSteps;

    showProgress(false);

    // Hide both card styles
    document.getElementById('plan-card').classList.add('hidden');
    document.getElementById('multi-plan-cards')?.remove();
    document.getElementById('phase-planning').classList.add('hidden');

    startRefinementPhase(currentPlan, data.steps, files);
}

// ── No-signal nudge ───────────────────────────────────────────────────────────

function handleNoSignal(data) {
    // The LLM responded without emitting any action signal.
    // The conversational response has already been streamed into the bubble,
    // so we just ensure the send button is re-enabled and do nothing else.
    // The user can continue the conversation naturally.
    setLoading(false);
    showProgress(false);
}

// ── Disambiguation card ───────────────────────────────────────────────────────

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
                const c      = typeColors[w.output_type] || typeColors.image;
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

// ── Workflow proposal (confirm before saving) ─────────────────────────────────

function handleWorkflowProposed(data) {
    showProgress(false);
    setLoading(false);

    document.getElementById('workflow-proposal-card')?.remove();

    const conv = document.getElementById('conversation');
    const card = document.createElement('div');
    card.id        = 'workflow-proposal-card';
    card.className = 'fade-in w-full';
    card.innerHTML = `
        <div class="bg-white border-2 border-amber-300 rounded-2xl overflow-hidden shadow-sm">
            <div class="bg-amber-50 border-b border-amber-200 px-5 py-3 flex items-center gap-2">
                <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                </svg>
                <span class="text-sm font-semibold text-amber-800">Create new workflow?</span>
            </div>
            <div class="px-5 py-4 space-y-2">
                <p class="text-sm text-gray-700">
                    A new workflow will be built for:
                    <span class="font-medium text-gray-900">"${data.intent ?? ''}"</span>
                </p>
                <p class="text-xs text-gray-500 mt-1">
                    If you just wanted to <span class="font-medium">generate media</span> with an existing
                    workflow, click <strong>Cancel</strong> and rephrase your request.
                </p>
            </div>
            <div class="px-5 pb-5 flex gap-3">
                <button id="btn-confirm-workflow"
                    class="flex-1 px-4 py-2.5 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-medium transition shadow-sm flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                    </svg>
                    Yes, build this workflow
                </button>
                <button id="btn-cancel-workflow"
                    class="flex-1 px-4 py-2.5 bg-white hover:bg-gray-50 text-gray-600 border border-gray-200 rounded-xl text-sm font-medium transition">
                    Cancel — generate instead
                </button>
            </div>
        </div>`;

    conv.appendChild(card);
    card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    // Accept: build & save
    document.getElementById('btn-confirm-workflow').addEventListener('click', async () => {
        const btn = document.getElementById('btn-confirm-workflow');
        btn.disabled  = true;
        btn.innerHTML = `<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg> Building…`;
        showProgress(true);

        try {
            const resp   = await fetch(STUDIO_ROUTES.workflowConfirm, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                body:    JSON.stringify({ intent: data.intent }),
            });
            const result = await resp.json();

            if (result.success) {
                card.innerHTML = `
                    <div class="bg-forest-50 border border-forest-200 rounded-2xl overflow-hidden shadow-sm">
                        <div class="bg-forest-500 px-5 py-3 flex items-center gap-2">
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                            </svg>
                            <span class="text-sm font-semibold text-white">Workflow saved!</span>
                        </div>
                        <div class="px-5 py-4 space-y-1">
                            <p class="text-sm font-medium text-gray-800">${result.name}</p>
                            <p class="text-xs text-gray-500">Output type: <span class="font-medium text-forest-700">${result.output_type}</span></p>
                            <p class="text-xs text-gray-400 italic">${result.description ?? ''}</p>
                            <p class="text-xs text-gray-500 mt-2">Redirecting to studio…</p>
                        </div>
                    </div>`;
                showProgress(false);
                setTimeout(() => { window.location.href = STUDIO_ROUTES.index; }, 2500);
            } else {
                showProgress(false);
                btn.disabled  = false;
                btn.innerHTML = 'Retry';
                appendMessage('assistant', '⚠ ' + (result.error ?? 'Workflow creation failed. Please try again.'));
            }
        } catch (e) {
            showProgress(false);
            btn.disabled  = false;
            btn.innerHTML = 'Retry';
            appendMessage('assistant', '⚠ Something went wrong. Please try again.');
        }
    });

    // Cancel: dismiss, let user rephrase
    document.getElementById('btn-cancel-workflow').addEventListener('click', () => {
        card.remove();
        appendMessage('assistant', 'No problem — what would you like to generate? I can create images, video, and audio with the existing workflows.');
        document.getElementById('user-input').focus();
    });
}

// ── Workflows Panel ───────────────────────────────────────────────────────────

function toggleWorkflowsPanel(forceOpen) {
    const panel   = document.getElementById('workflows-panel');
    const overlay = document.getElementById('workflows-panel-overlay');
    const isOpen  = panel.style.transform === 'translateX(0px)' || panel.style.transform === 'translateX(0)';
    const open    = forceOpen !== undefined ? forceOpen : !isOpen;

    panel.style.transform = open ? 'translateX(0)' : 'translateX(-100%)';
    overlay.classList.toggle('hidden', !open);

    if (open) renderWorkflowsPanel();
}

function renderWorkflowsPanel() {
    const list = document.getElementById('workflows-panel-list');
    if (!list) return;

    // Ensure STUDIO_WORKFLOWS is available and render
    if (typeof STUDIO_WORKFLOWS === 'undefined' || !STUDIO_WORKFLOWS) {
        list.innerHTML = `<div class="text-center text-gray-400 text-sm py-8">Loading workflows...</div>`;
        return;
    }

    const typeColors = {
        image: { badge: 'bg-blue-100 text-blue-700',   dot: 'bg-blue-400' },
        video: { badge: 'bg-purple-100 text-purple-700', dot: 'bg-purple-400' },
        audio: { badge: 'bg-amber-100 text-amber-700',  dot: 'bg-amber-400' },
    };

    if (STUDIO_WORKFLOWS.length === 0) {
        list.innerHTML = `<div class="text-center text-gray-400 text-sm py-8">No active workflows yet.</div>`;
        return;
    }

    // Group by output_type
    const groups = {};
    STUDIO_WORKFLOWS.forEach(w => {
        const t = w.output_type || 'other';
        if (!groups[t]) groups[t] = [];
        groups[t].push(w);
    });

    list.innerHTML = Object.entries(groups).map(([type, workflows]) => {
        const c = typeColors[type] || { badge: 'bg-gray-100 text-gray-700', dot: 'bg-gray-400' };
        return `
        <div class="mb-1">
            <div class="flex items-center gap-1.5 mb-1.5 px-1">
                <span class="w-1.5 h-1.5 rounded-full ${c.dot}"></span>
                <span class="text-[10px] font-semibold uppercase tracking-widest text-gray-400">${type}</span>
            </div>
            ${workflows.map(w => {
                const inputs = w.input_types?.length ? w.input_types.join(' + ') : 'text';
                return `
                <div class="bg-gray-50 hover:bg-forest-50 border border-gray-200 hover:border-forest-200 rounded-xl px-3 py-2.5 transition cursor-default group">
                    <div class="flex items-start justify-between gap-2">
                        <span class="text-xs font-semibold text-gray-800 group-hover:text-forest-800 leading-tight">${w.name}</span>
                        <span class="shrink-0 text-[10px] font-mono font-bold text-forest-600 bg-forest-50 border border-forest-200 px-1.5 py-0.5 rounded-md">ID:${w.id}</span>
                    </div>
                    <p class="text-[11px] text-gray-500 mt-1 leading-relaxed line-clamp-2">${w.description || ''}</p>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        <span class="text-[10px] px-1.5 py-0.5 rounded-full font-medium ${c.badge}">${type}</span>
                        <span class="text-[10px] text-gray-400">← ${inputs}</span>
                    </div>
                </div>`;
            }).join('')}
        </div>`;
    }).join('');
}

function exportPrompts() {
    const lines  = [];
    const intent = conversationHistory.find(m => m.role === 'user')?.content ?? 'Generation';
    lines.push(`AI Studio — Prompt Export`);
    lines.push(`Intent: ${intent}`);
    lines.push(`Exported: ${new Date().toLocaleString()}`);
    lines.push('');

    Object.entries(stepRefinementState).forEach(([order, state]) => {
        const card   = document.getElementById(`refine-step-${order}`);
        const prompt = card?.querySelector('.confirmed-text')?.textContent?.trim();
        if (prompt) {
            const purpose = card.querySelector('.text-forest-800')?.textContent?.trim() ?? `Step ${parseInt(order) + 1}`;
            lines.push(`--- Step ${parseInt(order) + 1}: ${purpose} ---`);
            lines.push(prompt);
            lines.push('');
        }
    });

    if (lines.length <= 4) { alert('No confirmed prompts to export yet.'); return; }

    const blob = new Blob([lines.join('\n')], { type: 'text/plain' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `prompts-${Date.now()}.txt`;
    a.click();
    URL.revokeObjectURL(url);
}

// Render workflows panel on page load
document.addEventListener('DOMContentLoaded', () => {
    renderWorkflowsPanel();
});