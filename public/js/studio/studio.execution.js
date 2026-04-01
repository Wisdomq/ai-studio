// ── AI Studio — Phase 3: Execution ───────────────────────────────────────────

// Stores storage-relative output paths keyed by step_order.
// Populated during polling so approveStep() can attach the path
// to dependent steps without an extra round-trip.
const stepOutputPaths = {};

// ── Dispatch ──────────────────────────────────────────────────────────────────

document.getElementById('btn-dispatch').addEventListener('click', async () => {
    showProgress(true);
    setPhase('Generating...');
    const resp = await fetch(`${STUDIO_ROUTES.planBase}/${currentPlanId}/dispatch`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
    });
    const data = await resp.json();
    if (!data.success) { alert(data.error); showProgress(false); return; }

    document.getElementById('phase-refinement').classList.add('hidden');
    document.getElementById('phase-execution').classList.remove('hidden');
    renderExecutionSteps();
    startPolling();
    setTimeout(() => document.getElementById('btn-continue-creating').classList.remove('hidden'), 3000);
    setTimeout(() => toggleMoodBoard(true), 5000);
});

document.getElementById('btn-add-to-queue').addEventListener('click', async () => {
    const resp = await fetch(`${STUDIO_ROUTES.planBase}/${currentPlanId}/queue`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
    });
    const data = await resp.json();

    if (data.success) {
        if (data.auto_dispatched) {
            document.getElementById('phase-refinement').classList.add('hidden');
            document.getElementById('phase-execution').classList.remove('hidden');
            renderExecutionSteps();
            startPolling();
        } else {
            appendMessage('assistant', `Added to queue (position ${data.queue_position}). You can start a new generation now!`);
        }
        refreshJobsPanel();
        resetToPlanning();
    }
});

// ── Execution rendering ───────────────────────────────────────────────────────

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

// ── Polling ───────────────────────────────────────────────────────────────────

function startPolling() {
    pollInterval = setInterval(pollStatus, 4000);
    pollStatus();
}

async function pollStatus() {
    try {
        const resp = await fetch(`${STUDIO_ROUTES.planBase}/${currentPlanId}/status`);
        const data = await resp.json();
        data.steps.forEach(step => {
            updateExecStep(step);
            // Cache the storage path so approveStep() can attach it to dependents
            if (step.output_path) {
                stepOutputPaths[step.step_order] = step.output_path;
            }
        });

        if (data.status === 'completed') {
            clearInterval(pollInterval);
            showProgress(false);
            setPhase('Complete! ✓');
            refreshJobsPanel();
            setTimeout(() => { window.location = `${STUDIO_ROUTES.planBase}/${currentPlanId}/result`; }, 1500);
        }
        if (data.status === 'failed') {
            clearInterval(pollInterval);
            showProgress(false);
            setPhase('Generation failed');
            refreshJobsPanel();
            showPlanFailedBanner();
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
        const retryBtn = errDiv.querySelector('.btn-retry-step');
        if (retryBtn && !retryBtn.dataset.wired) {
            retryBtn.dataset.wired = '1';
            retryBtn.addEventListener('click', () => retryPlan());
        }
    }
}

function renderMedia(container, url) {
    const ext = url.split('.').pop().toLowerCase();
    let el;
    if (['mp4','webm','mov'].includes(ext))        { el = document.createElement('video'); el.controls = true; el.className = 'w-full max-h-96 object-contain bg-black'; }
    else if (['mp3','wav','ogg','flac'].includes(ext)) { el = document.createElement('audio'); el.controls = true; el.className = 'w-full p-4'; }
    else                                               { el = document.createElement('img');  el.alt = 'Generated output'; el.className = 'w-full max-h-96 object-contain'; }
    el.src = url;
    container.appendChild(el);
}

// ── Step badge ────────────────────────────────────────────────────────────────

function updateStepBadge(el, status) {
    const badge = el.querySelector('.step-badge');
    const map = {
        pending:           ['Pending',         'badge-pending'],
        running:           ['Running\u2026',   'badge-running'],
        awaiting_approval: ['Review Required', 'badge-approval'],
        completed:         ['Approved \u2713', 'badge-completed'],
        failed:            ['Failed',          'badge-failed'],
    };
    const [label, cls] = map[status] ?? ['Unknown', 'badge-pending'];
    badge.textContent = label;
    badge.className   = `step-badge text-xs px-2.5 py-1 rounded-full font-medium ${cls}`;
}

// ── Approve / reject step ─────────────────────────────────────────────────────

async function approveStep(stepOrder) {
    const resp = await fetch(`${STUDIO_ROUTES.planBase}/${currentPlanId}/step/${stepOrder}/approve`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
    });
    if (!resp.ok) return;

    const el = document.getElementById(`exec-step-${stepOrder}`);
    updateStepBadge(el, 'completed');
    el.querySelector('.step-result').classList.add('hidden');

    // ── Auto-attach this step's output to any dependent steps ────────────────
    // Find the output_url for this step from the last polled status, then
    // POST it as input_file_path to all steps that depend on this step_order.
    // This ensures ExecutePlanJob has the file path available when it picks
    // up the next step — without requiring the user to manually re-upload.
    const dependentSteps = (currentPlan || []).filter(s =>
        Array.isArray(s.depends_on) && s.depends_on.includes(stepOrder)
    );

    if (dependentSteps.length > 0) {
        // Retrieve the output path from the step's cached status
        // (stored in lastPollData which we maintain below)
        const outputStoragePath = stepOutputPaths[stepOrder] ?? null;

        if (outputStoragePath) {
            await Promise.all(dependentSteps.map(dep =>
                fetch(`${STUDIO_ROUTES.planBase}/${currentPlanId}/step/${dep.step_order}/confirm`, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                    body:    JSON.stringify({
                        refined_prompt:  dep.refined_prompt || dep.purpose || dep.prompt_hint || '',
                        input_file_path: outputStoragePath,
                    }),
                })
            ));
        }
    }
}

async function rejectStep(stepOrder) {
    const resp = await fetch(`${STUDIO_ROUTES.planBase}/${currentPlanId}/step/${stepOrder}/reject`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
    });
    if (!resp.ok) return;

    const el = document.getElementById(`exec-step-${stepOrder}`);
    el.querySelector('.step-result').classList.add('hidden');
    updateStepBadge(el, 'pending');

    document.getElementById('phase-execution').classList.add('hidden');
    document.getElementById('phase-refinement').classList.remove('hidden');

    const planStep = currentPlan.find(s => s.step_order === stepOrder);
    if (!planStep) return;

    stepRefinementState[stepOrder] = {
        messages:   [{ role: 'user', content: planStep.prompt_hint || planStep.purpose }],
        turnNumber: 1,
        confirmed:  false,
        stepId:     stepRefinementState[stepOrder]?.stepId,
    };

    const existing = document.getElementById(`refine-step-${stepOrder}`);
    if (existing) existing.remove();

    renderRefinementCard(planStep, stepRefinementState[stepOrder].stepId);
    startRefinementStream(stepOrder);

    setTimeout(() => {
        const card = document.getElementById(`refine-step-${stepOrder}`);
        if (!card) return;
        card.querySelector('.btn-confirm-prompt')?.addEventListener('click', () => {
            setTimeout(() => {
                document.getElementById('phase-refinement').classList.add('hidden');
                document.getElementById('phase-execution').classList.remove('hidden');
                updateStepBadge(document.getElementById(`exec-step-${stepOrder}`), 'pending');
            }, 500);
        }, { once: true });
    }, 100);
}

// ── Retry plan ────────────────────────────────────────────────────────────────

async function retryPlan() {
    if (!currentPlanId) return;
    document.getElementById('plan-failed-banner')?.remove();
    showProgress(true);
    setPhase('Retrying...');

    const resp = await fetch(`${STUDIO_ROUTES.planBase}/${currentPlanId}/dispatch`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
    });
    const data = await resp.json();

    if (data.success) {
        document.querySelectorAll('.exec-step').forEach(el => {
            const badge = el.querySelector('.step-badge');
            if (badge && badge.textContent.includes('Failed')) {
                updateStepBadge(el, 'pending');
                el.querySelector('.step-error')?.classList.add('hidden');
            }
        });
        setPhase('Generating...');
        startPolling();
    } else {
        showProgress(false);
        setPhase('Generation failed');
        alert(data.error ?? 'Retry failed. Please try again.');
        showPlanFailedBanner();
    }
}

// ── Failed banner ─────────────────────────────────────────────────────────────

function showPlanFailedBanner() {
    document.getElementById('plan-failed-banner')?.remove();

    const banner = document.createElement('div');
    banner.id        = 'plan-failed-banner';
    banner.className = 'slide-in bg-red-50 border border-red-200 rounded-2xl px-5 py-4 flex items-center justify-between gap-4';
    banner.innerHTML = `
        <div class="flex items-center gap-3">
            <svg class="w-5 h-5 text-red-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div>
                <p class="text-sm font-semibold text-red-700">Generation failed</p>
                <p class="text-xs text-red-500 mt-0.5">Check the error above, then retry or start over.</p>
            </div>
        </div>
        <div class="flex gap-2 shrink-0">
            <button onclick="retryPlan()"
                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-xs font-semibold transition flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Retry
            </button>
            <button onclick="resetToPlanning()"
                class="px-4 py-2 bg-white hover:bg-gray-50 text-gray-600 border border-gray-200 rounded-xl text-xs font-medium transition">
                Start Over
            </button>
        </div>`;

    document.getElementById('phase-execution').appendChild(banner);
    banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}