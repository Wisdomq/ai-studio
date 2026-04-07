// ── AI Studio — Phase 2: Refinement ──────────────────────────────────────────

function startRefinementPhase(plan, steps, inputFiles = []) {
    document.getElementById('phase-refinement').classList.remove('hidden');
    setPhase('Refining prompts');

    const userIntent = conversationHistory
        .filter(m => m.role === 'user')
        .map(m => m.content)
        .join(' ')
        .replace(/\[Style:[^\]]*\]/g, '')
        .trim();

    // Build a step_order → output_type map from the approvePlan response.
    // This is the only place output_type is reliably available — currentPlan
    // items come from the LLM and never carry output_type.
    const outputTypeByOrder = {};
    plan.forEach((planStep, i) => {
        const ot = steps[i]?.output_type ?? null;
        if (ot) outputTypeByOrder[planStep.step_order] = ot;
    });

    plan.forEach((planStep, i) => {
        const stepId    = steps[i]?.id;
        const stepOrder = planStep.step_order;

        const enrichedStep = Object.assign({}, planStep, {
            input_types: steps[i]?.input_types ?? [],
            output_type: steps[i]?.output_type ?? null,
            depends_on:  planStep.depends_on ?? steps[i]?.depends_on ?? [],
        });

        const inputTypes = enrichedStep.input_types;

        // Types that upstream dependency steps will supply at runtime —
        // these must NOT get upload zones or be required in the file gate.
        const depCoveredTypes = (enrichedStep.depends_on ?? [])
            .map(depOrder => outputTypeByOrder[depOrder])
            .filter(Boolean);

        // Build a pre-populated inputFiles map from plan-level uploads,
        // but only for types the user must supply manually.
        const uploadableTypes = inputTypes.filter(t => !depCoveredTypes.includes(t));
        const preloadedMap    = {};
        if (uploadableTypes.length > 0 && inputFiles.length > 0) {
            uploadableTypes.forEach(type => {
                const match = inputFiles.find(f => f.media_type === type);
                if (match) preloadedMap[type] = match.storage_path;
            });
        }

        const seedContent = userIntent
            ? `I want to create: ${userIntent}${plan.length > 1 ? `\n\nFor this specific step: ${planStep.purpose}` : ''}`
            : planStep.prompt_hint || planStep.purpose;

        if (!stepRefinementState[stepOrder]) {
            stepRefinementState[stepOrder] = {
                messages:        [{ role: 'user', content: seedContent }],
                turnNumber:      1,
                confirmed:       false,
                stepId,
                inputFiles:      { ...preloadedMap },
                depCoveredTypes, // stored so renderRefinementCard + showConfirmedPrompt can read it
                isRedo:          false,
            };
        } else {
            // Redo path — preserve messages and inputFiles, update metadata
            stepRefinementState[stepOrder].stepId          = stepId;
            stepRefinementState[stepOrder].confirmed       = false;
            stepRefinementState[stepOrder].depCoveredTypes = depCoveredTypes;
            Object.entries(preloadedMap).forEach(([type, path]) => {
                if (!stepRefinementState[stepOrder].inputFiles[type]) {
                    stepRefinementState[stepOrder].inputFiles[type] = path;
                }
            });
        }

        renderRefinementCard(enrichedStep, stepId, preloadedMap, inputFiles, depCoveredTypes);
        startRefinementStream(stepOrder);
    });
}

/**
 * Prepare state for a redo after the user rejects a generated result.
 *
 * Called BEFORE startRefinementPhase() / startRefinementStream() so the
 * state is in place when the stream fires.
 *
 * @param {number} stepOrder   - The step being redone
 * @param {string} purpose     - Step purpose from the plan (e.g. "Generate image of…")
 * @param {string} userIntent  - Original user intent from conversationHistory
 * @param {string|null} rejectionReason - Optional reason the user gave when rejecting
 */
function reinitialiseStepForRedo(stepOrder, purpose, userIntent, rejectionReason = null) {
    const base = userIntent
        ? `I want to create: ${userIntent}\n\nFor this specific step: ${purpose}`
        : purpose;

    const redoSeed = rejectionReason
        ? `${base}\n\nNote: A previous attempt was generated but the result was not satisfactory. The user said: "${rejectionReason}". Please help refine a new prompt for this step.`
        : `${base}\n\nNote: A previous attempt was generated but the result was not satisfactory. Please help refine a new, improved prompt for this step.`;

    stepRefinementState[stepOrder] = {
        messages:        [{ role: 'user', content: redoSeed }],
        turnNumber:      1,
        confirmed:       false,
        stepId:          stepRefinementState[stepOrder]?.stepId ?? null,
        inputFiles:      { ...(stepRefinementState[stepOrder]?.inputFiles ?? {}) },
        depCoveredTypes: stepRefinementState[stepOrder]?.depCoveredTypes ?? [],
        isRedo:          true,
    };
}

function renderRefinementCard(planStep, stepId, preloadedMap = {}, allFiles = [], depCoveredTypes = []) {
    const container = document.getElementById('refinement-steps');

    // On redo, remove existing card so we re-render clean
    const existingCard = document.getElementById(`refine-step-${planStep.step_order}`);
    if (existingCard) existingCard.remove();

    const card     = document.createElement('div');
    card.id        = `refine-step-${planStep.step_order}`;
    card.className = 'slide-in bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm';

    const isRedo     = stepRefinementState[planStep.step_order]?.isRedo ?? false;
    const inputTypes = planStep.input_types ?? [];
    const state      = stepRefinementState[planStep.step_order];

    // Use the passed-in depCoveredTypes (computed in startRefinementPhase from the
    // approvePlan steps[] response — the only place output_type is reliable).
    // Fall back to state in case renderRefinementCard is called outside of
    // startRefinementPhase (e.g. directly on redo from rejectStep).
    const coveredTypes    = depCoveredTypes.length > 0
        ? depCoveredTypes
        : (state?.depCoveredTypes ?? []);
    const uploadableTypes = inputTypes.filter(t => !coveredTypes.includes(t));

    // ── Build one upload zone per manually-required input type ────────────────
    const uploadZonesHtml = uploadableTypes.map(type => {
        const preloadedPath = preloadedMap[type] ?? state?.inputFiles?.[type] ?? null;
        const preloadedFile = allFiles.find(f => f.storage_path === preloadedPath);
        const accept        = type === 'image' ? 'image/*' : type === 'video' ? 'video/*' : type === 'audio' ? 'audio/*' : '*/*';

        if (preloadedPath) {
            return `
            <div class="file-upload-zone border-t border-amber-100 bg-amber-50 px-5 py-3" data-media-type="${type}">
                <div class="flex items-center gap-2">
                    <svg class="w-3.5 h-3.5 text-amber-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                    </svg>
                    <span class="text-xs font-semibold text-amber-700 uppercase tracking-wide">${type}</span>
                    <div class="file-upload-done flex items-center gap-1.5 ml-auto text-xs text-forest-600">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                        </svg>
                        <span class="file-upload-done-text">${preloadedFile?.name ?? 'File ready'}</span>
                    </div>
                    <label class="ml-2 cursor-pointer text-xs text-amber-600 hover:text-amber-800 underline underline-offset-2">
                        Change
                        <input type="file" class="file-upload-input hidden" accept="${accept}" data-media-type="${type}">
                    </label>
                </div>
            </div>`;
        }

        return `
        <div class="file-upload-zone border-t border-amber-100 bg-amber-50 px-5 py-3" data-media-type="${type}">
            <div class="flex items-center gap-2 mb-2">
                <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                </svg>
                <span class="text-xs font-semibold text-amber-700 uppercase tracking-wide">Input Required</span>
                <span class="text-xs text-amber-600 font-normal">— ${type}</span>
            </div>
            <label class="file-upload-label flex items-center gap-3 cursor-pointer group">
                <div class="flex-1 flex items-center gap-2 px-4 py-2.5 bg-white border border-amber-200 rounded-xl hover:border-amber-400 transition text-sm text-gray-500 group-hover:text-gray-700">
                    <svg class="w-4 h-4 text-amber-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                    </svg>
                    <span class="file-upload-name">Choose ${type} file...</span>
                </div>
                <input type="file" class="file-upload-input hidden" accept="${accept}" data-media-type="${type}">
            </label>
            <div class="file-upload-status hidden mt-2 flex items-center gap-2 text-xs text-forest-600">
                <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <span>Uploading...</span>
            </div>
            <div class="file-upload-done hidden mt-2 flex items-center gap-2 text-xs text-forest-600">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="file-upload-done-text"></span>
            </div>
        </div>`;
    }).join('');

    card.innerHTML = `
        <div class="bg-forest-50 border-b border-forest-100 px-5 py-3">
            <div class="text-sm font-semibold text-forest-800">${planStep.purpose}</div>
            <div class="text-xs text-forest-600 mt-0.5">
                Step ${planStep.step_order + 1} · ${planStep.workflow_type}
                ${isRedo ? '<span class="ml-2 px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded text-xs font-medium">Redo</span>' : ''}
            </div>
        </div>
        ${uploadZonesHtml}
        <div class="refine-status px-5 pt-4 pb-2">
            <div class="flex items-center gap-2 text-sm text-gray-500">
                <svg class="w-4 h-4 animate-spin text-forest-500" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <span class="refine-status-text">${isRedo ? 'Refining for another attempt...' : 'Crafting your prompt...'}</span>
            </div>
        </div>
        <div class="refine-chat px-5 pb-4 space-y-3 text-sm"></div>
        <div class="refine-input-area hidden px-5 pb-5">
            <div class="flex gap-2 border border-gray-200 rounded-xl overflow-hidden focus-within:border-forest-400 focus-within:shadow-sm transition-all">
                <textarea rows="1" placeholder="Reply to refine further..."
                    class="flex-1 px-4 py-3 text-sm bg-transparent border-none resize-none focus:ring-0 text-gray-800 placeholder-gray-400"></textarea>
                <button class="btn-refine-reply px-4 bg-forest-500 hover:bg-forest-600 text-white text-sm font-medium transition shrink-0">Send</button>
            </div>
            <p class="text-xs text-gray-400 mt-1.5 ml-1">Enter to send · Shift+Enter for new line</p>
        </div>
        <div class="confirmed-prompt hidden border-t border-forest-100 bg-forest-50 px-5 py-4">
            <div class="flex items-center gap-2 mb-2">
                <svg class="w-4 h-4 text-forest-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-xs font-semibold text-forest-700 uppercase tracking-wide">Suggested Prompt</span>
                <span class="text-xs text-gray-400 font-normal ml-1">— reply below to refine, or confirm to use</span>
                <button class="btn-copy-prompt ml-auto p-1 text-gray-400 hover:text-forest-600 transition rounded" title="Copy prompt">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                </button>
            </div>
            <div class="confirmed-text text-sm text-gray-800 bg-white border border-forest-200 rounded-xl px-4 py-3 leading-relaxed" contenteditable="false"></div>
            <div class="upload-gate-warning hidden mt-2 flex items-center gap-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2">
                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span class="upload-gate-text">Please upload all required files before confirming.</span>
            </div>
            <div class="flex gap-2 mt-3">
                <button class="btn-confirm-prompt flex-1 py-2.5 bg-forest-500 hover:bg-forest-600 text-white rounded-xl text-sm font-medium transition">
                    ✓ Confirm &amp; Use This Prompt
                </button>
                <button class="btn-edit-prompt px-4 py-2.5 bg-white hover:bg-gray-50 text-gray-600 border border-gray-200 rounded-xl text-sm font-medium transition">Edit</button>
            </div>
        </div>`;

    container.appendChild(card);

    // ── Wire up each upload zone independently ────────────────────────────────
    card.querySelectorAll('.file-upload-zone').forEach(zone => {
        const fileInput = zone.querySelector('.file-upload-input');
        if (!fileInput) return;
        const mediaType = fileInput.dataset.mediaType;

        fileInput.addEventListener('change', async () => {
            const file = fileInput.files[0];
            if (!file) return;

            const nameEl   = zone.querySelector('.file-upload-name');
            const statusEl = zone.querySelector('.file-upload-status');
            const doneEl   = zone.querySelector('.file-upload-done');
            const doneText = zone.querySelector('.file-upload-done-text');

            if (nameEl) nameEl.textContent = file.name;
            statusEl?.classList.remove('hidden');
            doneEl?.classList.add('hidden');

            const formData = new FormData();
            formData.append('file', file);
            formData.append('media_type', mediaType);
            formData.append('_token', CSRF_TOKEN);

            try {
                const resp = await fetch(STUDIO_ROUTES.upload, { method: 'POST', body: formData });
                const data = await resp.json();
                if (resp.ok && data.storage_path) {
                    stepRefinementState[planStep.step_order].inputFiles[mediaType] = data.storage_path;
                    statusEl?.classList.add('hidden');
                    doneEl?.classList.remove('hidden');
                    if (doneText) doneText.textContent = `${file.name} ready`;
                    // Gate checks only manually-uploadable types
                    updateConfirmButtonGate(card, planStep.step_order, uploadableTypes);
                } else {
                    statusEl?.classList.add('hidden');
                    if (nameEl) nameEl.textContent = `Upload failed: ${data.message ?? 'Unknown error'}`;
                }
            } catch {
                statusEl?.classList.add('hidden');
                if (nameEl) nameEl.textContent = 'Upload error — try again';
            }
        });
    });

    // ── Copy prompt ───────────────────────────────────────────────────────────
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

    // ── Reply / refine ────────────────────────────────────────────────────────
    const replyBtn = card.querySelector('.btn-refine-reply');
    const replyTa  = card.querySelector('.refine-input-area textarea');

    const submitReply = () => {
        const reply = replyTa.value.trim();
        if (!reply) return;
        replyTa.value = '';
        card.querySelector('.confirmed-prompt').classList.add('hidden');
        stepRefinementState[planStep.step_order].messages.push({ role: 'user', content: reply });
        stepRefinementState[planStep.step_order].turnNumber++;
        startRefinementStream(planStep.step_order);
    };
    replyBtn.addEventListener('click', submitReply);
    replyTa.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submitReply(); } });

    // ── Edit prompt ───────────────────────────────────────────────────────────
    card.querySelector('.btn-edit-prompt').addEventListener('click', () => {
        const txt = card.querySelector('.confirmed-text');
        txt.contentEditable = txt.contentEditable === 'true' ? 'false' : 'true';
        if (txt.contentEditable === 'true') txt.focus();
        card.querySelector('.btn-edit-prompt').textContent = txt.contentEditable === 'true' ? 'Done' : 'Edit';
    });

    // ── Confirm prompt ────────────────────────────────────────────────────────
    card.querySelector('.btn-confirm-prompt').addEventListener('click', async () => {
        // Gate: only manually-uploadable types must have a file (dep-covered types are runtime-resolved)
        const missing = uploadableTypes.filter(t => !stepRefinementState[planStep.step_order].inputFiles[t]);
        if (missing.length > 0) {
            const warn = card.querySelector('.upload-gate-warning');
            const txt  = card.querySelector('.upload-gate-text');
            if (txt) txt.textContent = `Please upload the required ${missing.join(', ')} file${missing.length > 1 ? 's' : ''} before confirming.`;
            warn?.classList.remove('hidden');
            return;
        }

        const finalPrompt = card.querySelector('.confirmed-text').textContent.trim();
        const btn         = card.querySelector('.btn-confirm-prompt');
        btn.disabled      = true;
        btn.textContent   = 'Saving...';

        const stepOrder    = planStep.step_order;
        const stateNow     = stepRefinementState[stepOrder];
        const body         = { refined_prompt: finalPrompt };

        // Send multi-file map if any files are present
        if (Object.keys(stateNow.inputFiles ?? {}).length > 0) {
            body.input_files = stateNow.inputFiles;
        }

        try {
            const resp = await fetch(`${STUDIO_ROUTES.planBase}/${currentPlanId}/step/${stepOrder}/confirm`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                body:    JSON.stringify(body),
            });

            if (!resp.ok) {
                btn.disabled    = false;
                btn.textContent = 'Retry';
                return;
            }

            btn.textContent = '✓ Confirmed';
            btn.className   = btn.className.replace('bg-forest-500 hover:bg-forest-600', 'bg-gray-200 text-gray-500 cursor-default');
            card.classList.add('border-forest-300');
            card.querySelector('.refine-input-area')?.classList.add('hidden');
            stateNow.confirmed = true;
            checkAllConfirmed();
        } catch {
            btn.disabled    = false;
            btn.textContent = 'Retry';
        }
    }, { once: true });
}

async function startRefinementStream(stepOrder) {
    const state     = stepRefinementState[stepOrder];
    const card      = document.getElementById(`refine-step-${stepOrder}`);
    const chat      = card.querySelector('.refine-chat');
    const statusEl  = card.querySelector('.refine-status');
    const inputArea = card.querySelector('.refine-input-area');

    statusEl.classList.remove('hidden');
    inputArea.classList.add('hidden');

    const skeletonEl = document.createElement('div');
    skeletonEl.className = 'space-y-2 py-2';
    skeletonEl.innerHTML = `<div class="skeleton h-3 w-full"></div><div class="skeleton h-3 w-4/5"></div><div class="skeleton h-3 w-3/5"></div>`;
    chat.appendChild(skeletonEl);

    const controller = new AbortController();
    const timeout    = setTimeout(() => controller.abort(), 120000);
    let fullText     = '';

    try {
        const resp = await fetch(STUDIO_ROUTES.refineStep, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
            body:    JSON.stringify({
                plan_id:     currentPlanId,
                step_order:  stepOrder,
                messages:    state.messages,
                turn_number: state.turnNumber,
                is_redo:     state.isRedo ?? false,
            }),
            signal:  controller.signal,
        });

        skeletonEl.remove();
        statusEl.classList.add('hidden');

        const msgEl = document.createElement('div');
        msgEl.className = 'fade-in bg-gray-50 border border-gray-100 rounded-xl px-4 py-3 text-sm text-gray-700 leading-relaxed';
        chat.appendChild(msgEl);

        const reader = resp.body.getReader();
        const dec    = new TextDecoder();
        let buffer   = '', isDone = false;

        while (!isDone) {
            const { done, value } = await reader.read();
            if (done) break;
            buffer += dec.decode(value, { stream: true });
            const lines = buffer.split('\n'); buffer = lines.pop();
            for (const line of lines) {
                if (!line.startsWith('data: ')) continue;
                try {
                    const data = JSON.parse(line.slice(6));
                    if (data.type === 'chunk')    { fullText += data.content; msgEl.textContent = stripApprovalBlock(fullText) || ''; }
                    if (data.type === 'error')    { msgEl.textContent = '⚠ ' + data.message; msgEl.classList.add('text-amber-700', 'bg-amber-50', 'border-amber-200'); }
                    if (data.type === 'approved') { showConfirmedPrompt(stepOrder, data.prompt); }
                    if (data.type === 'done')     { isDone = true; break; }
                } catch(e) {}
            }
        }

        state.messages.push({ role: 'assistant', content: fullText });
        if (!state.confirmed) { inputArea.classList.remove('hidden'); inputArea.querySelector('textarea').focus(); }

    } catch(err) {
        skeletonEl.remove(); statusEl.classList.add('hidden');
        const errEl = document.createElement('div');
        errEl.className = 'text-sm text-red-500 px-1';
        errEl.textContent = err.name === 'AbortError' ? 'Timed out.' : 'Error: ' + err.message;
        chat.appendChild(errEl);
        inputArea.classList.remove('hidden');
    } finally {
        clearTimeout(timeout);
    }
}

/**
 * Check if all required input types for a step have uploaded files.
 * Dims the confirm button and shows a warning if any are missing.
 * Called after each successful upload and when the confirmed-prompt panel opens.
 */
function updateConfirmButtonGate(card, stepOrder, inputTypes) {
    const state      = stepRefinementState[stepOrder];
    const missing    = (inputTypes || []).filter(t => !state?.inputFiles?.[t]);
    const confirmBtn = card.querySelector('.btn-confirm-prompt');
    const warning    = card.querySelector('.upload-gate-warning');

    if (missing.length > 0) {
        confirmBtn?.classList.add('opacity-50');
        // Don't show warning yet — only shown on failed confirm attempt
    } else {
        confirmBtn?.classList.remove('opacity-50');
        warning?.classList.add('hidden');
    }
}

function showConfirmedPrompt(stepOrder, prompt) {
    const card       = document.getElementById(`refine-step-${stepOrder}`);
    const state      = stepRefinementState[stepOrder] ?? {};
    const inputTypes = (currentPlan || []).find(s => s.step_order === stepOrder)?.input_types
                    ?? [];

    // depCoveredTypes is stored in state by startRefinementPhase — reliable because
    // it was computed from the approvePlan steps[] response, not from currentPlan.
    const coveredTypes    = state.depCoveredTypes ?? [];
    const uploadableTypes = inputTypes.filter(t => !coveredTypes.includes(t));

    card.querySelector('.confirmed-text').textContent = prompt;
    card.querySelector('.confirmed-prompt').classList.remove('hidden');
    card.querySelector('.refine-status')?.classList.add('hidden');

    updateConfirmButtonGate(card, stepOrder, uploadableTypes);
}

function checkAllConfirmed() {
    const allConfirmed = Object.values(stepRefinementState).every(s => s.confirmed);
    if (!allConfirmed) return;

    const isRedo = Object.values(stepRefinementState).some(s => s.isRedo);

    if (isRedo) {
        document.getElementById('dispatch-actions')?.classList.add('hidden');
        document.getElementById('btn-dispatch')?.click();
        document.getElementById('phase-refinement').classList.add('hidden');
        document.getElementById('phase-execution').classList.remove('hidden');
    } else {
        document.getElementById('dispatch-actions').classList.remove('hidden');
        document.getElementById('dispatch-actions').classList.add('fade-in');
    }
}