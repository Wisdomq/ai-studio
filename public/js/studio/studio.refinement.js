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

    plan.forEach((planStep, i) => {
        const stepId    = steps[i]?.id;
        const stepOrder = planStep.step_order;

        const enrichedStep = Object.assign({}, planStep, {
            input_types: steps[i]?.input_types ?? [],
        });

        const seedContent = userIntent
            ? `I want to create: ${userIntent}${plan.length > 1 ? `\n\nFor this specific step: ${planStep.purpose}` : ''}`
            : planStep.prompt_hint || planStep.purpose;

        const inputTypes = steps[i]?.input_types ?? [];
        const preloadedFile = (i === 0 && inputFiles.length > 0 && inputTypes.length > 0) 
            ? inputFiles.find(f => inputTypes.includes(f.media_type))?.storage_path ?? null
            : null;

        stepRefinementState[stepOrder] = {
            messages:      [{ role: 'user', content: seedContent }],
            turnNumber:    1,
            confirmed:     false,
            stepId,
            inputFilePath: preloadedFile,
        };
        renderRefinementCard(enrichedStep, stepId, preloadedFile, inputFiles);
        startRefinementStream(stepOrder);
    });
}

function renderRefinementCard(planStep, stepId, preloadedFile = null, allFiles = []) {
    const container = document.getElementById('refinement-steps');
    const card      = document.createElement('div');
    card.id         = `refine-step-${planStep.step_order}`;
    card.className  = 'slide-in bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm';

    const inputTypes   = planStep.input_types ?? [];
    const needsFile    = inputTypes.length > 0;
    const fileLabel    = inputTypes.join(' / ');
    
    let preloadedHtml = '';
    if (preloadedFile) {
        const file = allFiles.find(f => f.storage_path === preloadedFile);
        preloadedHtml = `
            <div class="file-upload-done flex items-center gap-2 text-xs text-forest-600">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="file-upload-done-text">${file?.name ?? 'File ready'}</span>
            </div>`;
    }

    const fileUploadHtml = needsFile ? `
        <div class="file-upload-area border-t border-amber-100 bg-amber-50 px-5 py-4">
            <div class="flex items-center gap-2 mb-2">
                <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                </svg>
                <span class="text-xs font-semibold text-amber-700 uppercase tracking-wide">Input File Required</span>
                <span class="text-xs text-amber-600 font-normal">— ${fileLabel}</span>
            </div>
            ${preloadedFile ? preloadedHtml : `
            <label class="file-upload-label flex items-center gap-3 cursor-pointer group">
                <div class="flex-1 flex items-center gap-2 px-4 py-2.5 bg-white border border-amber-200 rounded-xl hover:border-amber-400 transition text-sm text-gray-500 group-hover:text-gray-700">
                    <svg class="w-4 h-4 text-amber-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                    </svg>
                    <span class="file-upload-name">Choose ${fileLabel} file...</span>
                </div>
                <input type="file" class="file-upload-input hidden" accept="${buildFileAccept(inputTypes)}">
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
            `}
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

    // ── File upload ───────────────────────────────────────────────────────────
    const fileInput = card.querySelector('.file-upload-input');
    if (fileInput) {
        stepRefinementState[planStep.step_order].inputFilePath = null;

        fileInput.addEventListener('change', async () => {
            const file = fileInput.files[0];
            if (!file) return;

            const nameEl   = card.querySelector('.file-upload-name');
            const statusEl = card.querySelector('.file-upload-status');
            const doneEl   = card.querySelector('.file-upload-done');
            const doneText = card.querySelector('.file-upload-done-text');

            nameEl.textContent = file.name;
            statusEl.classList.remove('hidden');
            doneEl.classList.add('hidden');

            const mediaType = file.type.startsWith('video/') ? 'video'
                            : file.type.startsWith('audio/') ? 'audio'
                            : 'image';

            const formData = new FormData();
            formData.append('file', file);
            formData.append('media_type', mediaType);
            formData.append('_token', CSRF_TOKEN);

            try {
                const resp = await fetch(STUDIO_ROUTES.upload, { method: 'POST', body: formData });
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
        const finalPrompt = card.querySelector('.confirmed-text').textContent.trim();
        const btn = card.querySelector('.btn-confirm-prompt');
        btn.disabled     = true;
        btn.textContent  = 'Saving...';

        const body = { refined_prompt: finalPrompt };
        const inputFilePath = stepRefinementState[planStep.step_order]?.inputFilePath;
        if (inputFilePath) body.input_file_path = inputFilePath;

        await fetch(`${STUDIO_ROUTES.planBase}/${currentPlanId}/step/${planStep.step_order}/confirm`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
            body:    JSON.stringify(body),
        });

        btn.textContent = '\u2713 Confirmed';
        btn.className   = btn.className.replace('bg-forest-500 hover:bg-forest-600', 'bg-gray-200 text-gray-500 cursor-default');
        card.classList.add('border-forest-300');
        card.querySelector('.refine-input-area').classList.add('hidden');
        stepRefinementState[planStep.step_order].confirmed = true;
        checkAllConfirmed();
    });
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
            body:    JSON.stringify({ plan_id: currentPlanId, step_order: stepOrder, messages: state.messages, turn_number: state.turnNumber }),
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