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
    startQueueStatusPolling();
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
            startQueueStatusPolling();
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
    
    // Group steps by execution_layer
    const stepsByLayer = {};
    currentPlan.forEach(planStep => {
        const layer = planStep.execution_layer ?? 0;
        if (!stepsByLayer[layer]) stepsByLayer[layer] = [];
        stepsByLayer[layer].push(planStep);
    });
    
    // Render each layer with enhanced header
    const layers = Object.keys(stepsByLayer).sort((a, b) => parseInt(a) - parseInt(b));
    layers.forEach((layer, layerIndex) => {
        // Create enhanced layer header
        const layerHeader = document.createElement('div');
        layerHeader.className = 'slide-in';
        layerHeader.style.animationDelay = `${layerIndex * 0.1}s`;
        layerHeader.innerHTML = `
            <div class="flex items-center gap-3 mb-4 mt-6 first:mt-0">
                <div class="flex items-center gap-2">
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-forest-500 to-forest-600 text-white flex items-center justify-center text-sm font-bold shadow-md">
                        ${layer}
                    </div>
                    <div>
                        <span class="text-sm font-semibold text-gray-800 block">
                            Layer ${layer}
                        </span>
                        <span class="text-xs text-gray-500">
                            ${layer == 0 ? 'Independent Steps' : `Depends on Layer ${layer - 1}`}
                        </span>
                    </div>
                </div>
                <div class="flex-1 h-px bg-gradient-to-r from-gray-300 to-transparent"></div>
                <span class="text-xs font-medium text-gray-500 bg-gray-100 px-2.5 py-1 rounded-full">
                    ${stepsByLayer[layer].length} step${stepsByLayer[layer].length > 1 ? 's' : ''}
                </span>
            </div>
        `;
        container.appendChild(layerHeader);
        
        // Create grid container for steps in this layer
        const stepsGrid = document.createElement('div');
        stepsGrid.className = 'grid grid-cols-1 md:grid-cols-2 gap-4 pl-12';
        
        // Render steps in this layer
        stepsByLayer[layer].forEach((planStep, stepIndex) => {
            const tpl  = document.getElementById('tpl-exec-step');
            const node = tpl.content.cloneNode(true);
            const el   = node.querySelector('.exec-step');
            el.id      = `exec-step-${planStep.step_order}`;
            el.className += ' slide-in';
            el.style.animationDelay = `${(layerIndex * 0.1) + (stepIndex * 0.05)}s`;
            el.querySelector('.step-name').textContent = planStep.purpose;
            
            // Show dependencies if any
            if (planStep.depends_on && Object.keys(planStep.depends_on).length > 0) {
                const depsDiv = el.querySelector('.step-dependencies');
                const depsArray = Object.entries(planStep.depends_on).map(([type, stepOrder]) => {
                    const depStep = currentPlan.find(s => s.step_order === stepOrder);
                    return `<span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-100 rounded text-xs">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                        </svg>
                        ${type} from step ${stepOrder + 1}
                    </span>`;
                });
                depsDiv.innerHTML = `<div class="flex items-center gap-1 flex-wrap">${depsArray.join('')}</div>`;
                depsDiv.classList.remove('hidden');
            }
            
            updateStepBadge(el, 'pending');
            stepsGrid.appendChild(el);
        });
        
        container.appendChild(stepsGrid);
        
        // Add visual connector to next layer
        if (layerIndex < layers.length - 1) {
            const connector = document.createElement('div');
            connector.className = 'flex justify-center my-6 slide-in';
            connector.style.animationDelay = `${(layerIndex + 1) * 0.1}s`;
            connector.innerHTML = `
                <div class="flex flex-col items-center gap-2">
                    <svg class="w-6 h-6 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                    </svg>
                    <span class="text-xs text-gray-400 font-medium">Then</span>
                </div>
            `;
            container.appendChild(connector);
        }
    });
}

// ── Step polling ──────────────────────────────────────────────────────────────

function startPolling() {
    pollInterval = setInterval(pollStatus, 4000);
    pollStatus();
}

async function pollStatus() {
    try {
        const resp = await fetch(`${STUDIO_ROUTES.planBase}/${currentPlanId}/status`);
        const data = await resp.json();
        
        const hasOrphaned = data.steps.some(s => s.status === 'orphaned');
        const noticeEl = document.getElementById('orphaned-notice');
        if (noticeEl) {
            noticeEl.classList.toggle('hidden', !hasOrphaned);
        }
        
        // Update progress bar
        updateProgressBar(data.steps);
        
        data.steps.forEach(step => {
            updateExecStep(step);
            if (step.output_path) {
                stepOutputPaths[step.step_order] = step.output_path;
            }
        });

        if (data.status === 'completed') {
            clearInterval(pollInterval);
            stopQueueStatusPolling();
            showProgress(false);
            setPhase('Complete! ✓');
            updateProgressBar(data.steps, true); // Final update
            refreshJobsPanel();
            setTimeout(() => { window.location = `${STUDIO_ROUTES.planBase}/${currentPlanId}/result`; }, 1500);
        }
        if (data.status === 'failed') {
            clearInterval(pollInterval);
            stopQueueStatusPolling();
            showProgress(false);
            setPhase('Generation failed');
            refreshJobsPanel();
            showPlanFailedBanner();
        }
    } catch(e) {}
}

// ── Progress Bar Update ───────────────────────────────────────────────────────

function updateProgressBar(steps, isComplete = false) {
    const totalSteps = steps.length;
    const completedSteps = steps.filter(s => s.status === 'completed').length;
    const runningSteps = steps.filter(s => s.status === 'running').length;
    const failedSteps = steps.filter(s => s.status === 'failed').length;
    
    const percentage = totalSteps > 0 ? Math.round((completedSteps / totalSteps) * 100) : 0;
    
    // Update progress bar
    const progressBar = document.getElementById('progress-bar');
    const progressText = document.getElementById('progress-text');
    const progressStatus = document.getElementById('progress-status');
    const progressPercentage = document.getElementById('progress-percentage');
    
    if (progressBar) {
        progressBar.style.width = `${percentage}%`;
        
        // Change color based on status
        if (isComplete) {
            progressBar.className = 'bg-gradient-to-r from-green-500 to-green-600 h-3 rounded-full progress-bar-animated shadow-sm';
        } else if (failedSteps > 0) {
            progressBar.className = 'bg-gradient-to-r from-red-500 to-red-600 h-3 rounded-full progress-bar-animated shadow-sm';
        } else if (runningSteps > 0) {
            progressBar.className = 'bg-gradient-to-r from-blue-500 to-blue-600 h-3 rounded-full progress-bar-animated shadow-sm';
        } else {
            progressBar.className = 'bg-gradient-to-r from-forest-500 to-forest-600 h-3 rounded-full progress-bar-animated shadow-sm';
        }
    }
    
    if (progressText) {
        progressText.textContent = `${completedSteps} / ${totalSteps} steps`;
    }
    
    if (progressPercentage) {
        progressPercentage.textContent = `${percentage}%`;
    }
    
    if (progressStatus) {
        if (isComplete) {
            progressStatus.textContent = '✓ All steps completed';
        } else if (failedSteps > 0) {
            progressStatus.textContent = `${failedSteps} step${failedSteps > 1 ? 's' : ''} failed`;
        } else if (runningSteps > 0) {
            progressStatus.textContent = `${runningSteps} step${runningSteps > 1 ? 's' : ''} running...`;
        } else {
            progressStatus.textContent = 'Preparing...';
        }
    }
}

function updateExecStep(step) {
    const el = document.getElementById(`exec-step-${step.step_order}`);
    if (!el) return;
    updateStepBadge(el, step.status);

    // Show/hide cancel button based on status
    const cancelBtn = el.querySelector('.btn-cancel-step');
    if (cancelBtn) {
        if (step.status === 'running') {
            cancelBtn.classList.remove('hidden');
            cancelBtn.classList.add('flex');
            if (!cancelBtn.dataset.wired) {
                cancelBtn.dataset.wired = '1';
                cancelBtn.addEventListener('click', () => cancelStep(step.step_order));
            }
        } else {
            cancelBtn.classList.add('hidden');
            cancelBtn.classList.remove('flex');
        }
    }

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

    if (step.status === 'cancelled') {
        el.querySelector('.step-cancelled')?.classList.remove('hidden');
        el.querySelector('.step-result')?.classList.add('hidden');
        el.querySelector('.step-error')?.classList.add('hidden');
    }

    if (step.status === 'failed' && step.error_message) {
        el.querySelector('.step-error-text').textContent = step.error_message;
        el.querySelector('.step-error').classList.remove('hidden');
        const retryBtn = el.querySelector('.btn-retry-step');
        if (retryBtn && !retryBtn.dataset.wired) {
            retryBtn.dataset.wired = '1';
            retryBtn.addEventListener('click', () => retryPlan());
        }
    }
}

function renderMedia(container, url) {
    const ext = url.split('.').pop().toLowerCase();
    let el;
    if (['mp4','webm','mov'].includes(ext))            { el = document.createElement('video'); el.controls = true; el.className = 'w-full max-h-96 object-contain bg-black'; }
    else if (['mp3','wav','ogg','flac'].includes(ext)) { el = document.createElement('audio'); el.controls = true; el.className = 'w-full p-4'; }
    else                                               { el = document.createElement('img');  el.alt = 'Generated output'; el.className = 'w-full max-h-96 object-contain'; }
    el.src = url;
    container.appendChild(el);
}

// ── Step badge ────────────────────────────────────────────────────────────────

function updateStepBadge(el, status) {
    const badge = el.querySelector('.step-badge');
    
    // Status configuration with icons
    const statusConfig = {
        pending: {
            label: 'Pending',
            class: 'badge-pending',
            icon: '<svg class="w-3 h-3 inline-block mr-1" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="10" r="3"/></svg>'
        },
        running: {
            label: 'Running',
            class: 'badge-running',
            icon: '<svg class="w-3 h-3 inline-block mr-1 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>'
        },
        awaiting_approval: {
            label: 'Review Required',
            class: 'badge-approval',
            icon: '<svg class="w-3 h-3 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>'
        },
        completed: {
            label: 'Approved',
            class: 'badge-completed',
            icon: '<svg class="w-3 h-3 inline-block mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>'
        },
        failed: {
            label: 'Failed',
            class: 'badge-failed',
            icon: '<svg class="w-3 h-3 inline-block mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>'
        },
        cancelled: {
            label: 'Cancelled',
            class: 'badge-cancelled',
            icon: '<svg class="w-3 h-3 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>'
        },
        orphaned: {
            label: 'Still Running',
            class: 'badge-orphaned',
            icon: '<svg class="w-3 h-3 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
        }
    };
    
    const config = statusConfig[status] || statusConfig.pending;
    badge.innerHTML = config.icon + config.label;
    badge.className = `step-badge inline-flex items-center text-xs px-3 py-1.5 rounded-full font-medium ${config.class}`;
    
    // Update parent card border color
    const card = el.closest('.exec-step');
    if (card) {
        card.setAttribute('data-status', status);
    }
}

// ── Cancel step ───────────────────────────────────────────────────────────────

async function cancelStep(stepOrder) {
    if (!confirm('Cancel this generation step?')) return;

    const el        = document.getElementById(`exec-step-${stepOrder}`);
    const cancelBtn = el?.querySelector('.btn-cancel-step');
    if (cancelBtn) { cancelBtn.disabled = true; cancelBtn.textContent = 'Cancelling…'; }

    try {
        const resp = await fetch(`${STUDIO_ROUTES.planBase}/${currentPlanId}/step/${stepOrder}/cancel`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        });
        const data = await resp.json();

        if (data.success) {
            updateStepBadge(el, 'cancelled');
            if (cancelBtn) { cancelBtn.classList.add('hidden'); cancelBtn.classList.remove('flex'); }
            el?.querySelector('.step-cancelled')?.classList.remove('hidden');
        } else {
            if (cancelBtn) { cancelBtn.disabled = false; cancelBtn.textContent = 'Cancel'; }
            console.warn('Cancel failed:', data.error);
        }
    } catch (e) {
        if (cancelBtn) { cancelBtn.disabled = false; cancelBtn.textContent = 'Cancel'; }
    }
}

// ── Helper: Find dependent steps (handles both flat and keyed formats) ────────

function findDependentSteps(stepOrder) {
    return (currentPlan || []).filter(s => {
        const dep = s.depends_on;
        if (!dep) return false;
        if (Array.isArray(dep)) return dep.includes(stepOrder);  // flat: [0, 1]
        if (typeof dep === 'object') return Object.values(dep).includes(stepOrder); // keyed: {image: 0}
        return false;
    });
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
    // Each dependent step receives the output keyed by this step's output_type
    // so multi-input workflows accumulate files from multiple upstream steps
    // without overwriting each other (confirmStep() merges on the backend).
    const dependentSteps = findDependentSteps(stepOrder);

    if (dependentSteps.length > 0) {
        const outputStoragePath = stepOutputPaths[stepOrder] ?? null;
        // output_type is injected by approvePlan() — tells us what media type this step produced
        const outputMediaType   = (currentPlan || []).find(s => s.step_order === stepOrder)?.output_type ?? null;

        if (outputStoragePath && outputMediaType) {
            await Promise.all(dependentSteps.map(dep =>
                fetch(`${STUDIO_ROUTES.planBase}/${currentPlanId}/step/${dep.step_order}/confirm`, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                    body:    JSON.stringify({
                        // No refined_prompt — confirmStep() skips it when already set
                        input_files: { [outputMediaType]: outputStoragePath },
                    }),
                })
            ));
        }
    }
}

async function rejectStep(stepOrder) {
    const reason = window.prompt('What was wrong with the result? (optional - Press Ok to skip)',
        ''
    ) ?? '';
    const resp = await fetch(`${STUDIO_ROUTES.planBase}/${currentPlanId}/step/${stepOrder}/reject`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        body:    JSON.stringify({ rejection_reason: reason.trim() || null }),
    });
    if (!resp.ok){
        console.error ('Reject step failed', await resp.text());
        return;
    }

    const data = await resp.json();

    //data now contains : success, step_order, status, purpose, user_intent, rejection_reason.
    //Use it to rebuild a grounded redo seed-prevents hallucination on re-entry.

    const userIntent = data.user_intent
        ?? conversationHistory.filter(m=>m.role === 'user').map(m=>m.content).join('').trim();

    // Look up the step from currentPlan — planStepData is not a valid variable here.
    const planStep = (currentPlan || []).find(s => s.step_order === stepOrder) ?? {};

    reinitialiseStepForRedo(
        stepOrder,
        data.purpose ?? planStep.purpose ?? '',
        userIntent,
        data.rejection_reason ?? null
    );

    // Show a brief message explaining the redo before the refinement card appears.
    const stepPurpose = data.purpose ?? planStep.purpose ?? `Step ${stepOrder + 1}`;
    const redoMsg = data.rejection_reason
        ? `Let's refine "${stepPurpose}". You said: "${data.rejection_reason}"`
        : `Let's refine "${stepPurpose}" and try again.`;
    appendMessage('assistant', redoMsg);

    document.getElementById('phase-execution').classList.add('hidden');
    document.getElementById('phase-refinement').classList.remove('hidden');

    const el = document.getElementById(`exec-step-${stepOrder}`);
    el.querySelector('.step-result').classList.add('hidden');
    el.querySelector('.step-media-wrap').innerHTML = '';
    updateStepBadge(el, 'pending');

    const enrichedStep = Object.assign({}, planStep, {
        input_types: planStep.input_types ?? [],
    });
    renderRefinementCard(enrichedStep, stepRefinementState[stepOrder]?.stepId, null, []);
    startRefinementStream(stepOrder);
}


// ── Queue status polling ──────────────────────────────────────────────────────
// Polls /studio/queue-status every 10s while in execution phase.
// Shows "N jobs ahead of yours" in the amber bar when the ComfyUI queue
// has pending jobs. Hides the bar when the queue is clear or a step is running.

let queueStatusInterval = null;

function startQueueStatusPolling() {
    queueStatusInterval = setInterval(fetchQueueStatus, 10000);
    fetchQueueStatus(); // Immediate first check
}

function stopQueueStatusPolling() {
    if (queueStatusInterval) {
        clearInterval(queueStatusInterval);
        queueStatusInterval = null;
    }
    document.getElementById('queue-status-bar')?.classList.add('hidden');
}

async function fetchQueueStatus() {
    try {
        const resp = await fetch(STUDIO_ROUTES.queueStatus);
        if (!resp.ok) return;
        const data = await resp.json();

        const bar  = document.getElementById('queue-status-bar');
        const text = document.getElementById('queue-status-text');
        if (!bar || !text) return;

        const pending = data.pending ?? 0;
        const running = data.running ?? 0;

        if (pending > 0) {
            const jobWord = pending === 1 ? 'job' : 'jobs';
            text.textContent = `${pending} ${jobWord} ahead of yours in the ComfyUI queue…`;
            bar.classList.remove('hidden');
        } else if (running > 0) {
            // Our job is actively running — no need to show the queue bar
            bar.classList.add('hidden');
        } else {
            bar.classList.add('hidden');
        }
    } catch (e) {
        // Network error — silently ignore, don't break the execution UI
    }
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
        startQueueStatusPolling();
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