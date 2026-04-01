// ── AI Studio — Jobs Panel, Queue & Mood Board ────────────────────────────────

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
            fetch(STUDIO_ROUTES.jobs),
            fetch(STUDIO_ROUTES.queueStatus),
        ]);
        const jobs   = await jobsResp.json();
        const status = await statusResp.json();
        renderJobsList(jobs.plans);
        updateQueueSummary(status);
    } catch(e) {}
}

async function deleteJob(planId) {
    if (!confirm('Delete this job?')) return;
    try {
        const resp = await fetch(`${STUDIO_ROUTES.planBase}/${planId}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': CSRF_TOKEN },
        });
        if (resp.ok) refreshJobsPanel();
    } catch(e) {}
}

function renderJobsList(plans) {
    const list = document.getElementById('jobs-list');
    if (!plans || plans.length === 0) {
        list.innerHTML = `<div class="text-center text-gray-400 text-sm py-8">
            <svg class="w-8 h-8 mx-auto mb-2 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>No jobs yet</div>`;
        return;
    }

    const statusConfig = {
        pending:   { label: 'Pending',  cls: 'badge-pending'   },
        queued:    { label: 'Queued',   cls: 'badge-queued'    },
        running:   { label: 'Running',  cls: 'badge-running'   },
        completed: { label: 'Done \u2713', cls: 'badge-completed' },
        failed:    { label: 'Failed',   cls: 'badge-failed'    },
    };

    list.innerHTML = plans.map(plan => {
        const sc         = statusConfig[plan.status] || statusConfig.pending;
        const firstOutput = plan.steps?.find(s => s.output_url)?.output_url;
        const isAwaiting  = plan.steps?.some(s => s.status === 'awaiting_approval');

        return `
        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden hover:border-forest-300 transition relative group">
            <button onclick="deleteJob(${plan.plan_id})" 
                class="absolute top-2 right-2 p-1.5 bg-white/80 hover:bg-red-50 text-gray-400 hover:text-red-500 rounded-lg opacity-0 group-hover:opacity-100 transition z-10"
                title="Delete job">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </button>
            ${firstOutput
                ? `<div class="h-24 bg-gray-100 overflow-hidden">
                    <img src="${firstOutput}" class="w-full h-full object-cover" onerror="this.parentElement.style.display='none'">
                   </div>`
                : `<div class="h-1 ${plan.status === 'running' ? 'bg-forest-200' : plan.status === 'queued' ? 'bg-amber-200' : 'bg-gray-100'}">
                    ${plan.status === 'running' ? '<div class="progress-bar h-full"></div>' : ''}
                   </div>`}
            <div class="px-3 py-2.5">
                <div class="flex items-start justify-between gap-2 mb-1.5">
                    <div class="text-xs font-medium text-gray-800 line-clamp-1 flex-1">${plan.user_intent || 'Untitled generation'}</div>
                    <span class="text-xs px-2 py-0.5 rounded-full font-medium shrink-0 ${sc.cls}">${sc.label}</span>
                </div>
                <div class="flex items-center gap-2">
                    ${plan.status === 'running'
                        ? `<div class="text-xs text-forest-600 flex items-center gap-1">
                            <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            Generating...</div>`
                        : ''}
                    ${isAwaiting    ? `<a href="${STUDIO_ROUTES.planBase}/${plan.plan_id}/review" class="text-xs text-blue-600 hover:text-blue-700 font-medium">Review result \u2192</a>` : ''}
                    ${plan.status === 'completed' ? `<a href="${STUDIO_ROUTES.planBase}/${plan.plan_id}/result" class="text-xs text-forest-600 hover:text-forest-700 font-medium">View result \u2192</a>` : ''}
                    ${plan.status === 'queued'    ? `<span class="text-xs text-amber-600">Queue #${plan.queue_position}</span>` : ''}
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
    const total   = status.total_active || 0;

    if (total > 0) {
        badge.textContent  = total; badge.classList.remove('hidden');
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

    runBtn.classList.toggle('hidden', !(status.queued > 0 && status.running === 0));
}

async function runNextInQueue() {
    const resp = await fetch(STUDIO_ROUTES.runQueue, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
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
        btn.style.outline   = '';
        btn.style.transform = '';
    } else {
        moodBoardSelections.colors.push({ color, name });
        btn.style.outline       = '3px solid #2da62d';
        btn.style.outlineOffset = '2px';
        btn.style.transform     = 'scale(1.15)';
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
    if (moodBoardSelections.tags.length)   parts.push(moodBoardSelections.tags.join(', '));
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
    document.getElementById('mood-active-dot').classList.remove('hidden');
    closeMoodBoard();
    if (currentPlanId) {
        fetch(`${STUDIO_ROUTES.planBase}/${currentPlanId}/mood-board`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
            body:    JSON.stringify({ mood_board: moodBoardSelections }),
        });
    }
}

function clearMoodBoard() {
    moodBoardSelections = { colors: [], tags: [] };
    moodBoardApplied    = false;
    document.getElementById('mood-active-dot').classList.add('hidden');
    document.querySelectorAll('.mood-swatch').forEach(b => { b.style.outline = ''; b.style.transform = ''; });
    document.querySelectorAll('.mood-tag').forEach(b => b.classList.remove('selected', 'bg-forest-500', 'text-white', 'border-forest-500'));
    updateMoodPreview();
}