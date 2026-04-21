// ── AI Studio — Core State & Helpers ─────────────────────────────────────────
// Depends on: STUDIO_ROUTES injected by index.blade.php

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

function setPhase(label) {
    document.getElementById('phase-indicator').textContent = label;
}

function showProgress(show) {
    document.getElementById('top-progress').classList.toggle('hidden', !show);
}

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
    conversationHistory     = [];
    currentPlan             = null;
    currentPlanId           = null;
    stepRefinementState     = {};

    // planCardApprovals is declared in studio.planning.js but reset here
    // so it is always cleared when the user starts over.
    if (typeof planCardApprovals !== 'undefined') {
        planCardApprovals = {};
    }

    // Clear all dynamic content
    document.getElementById('conversation').innerHTML    = '';
    document.getElementById('plan-card').classList.add('hidden');
    document.getElementById('multi-plan-cards')?.remove();
    document.getElementById('phase-planning').classList.remove('hidden');
    document.getElementById('phase-refinement').classList.add('hidden');
    document.getElementById('phase-execution').classList.add('hidden');
    document.getElementById('dispatch-actions').classList.add('hidden');
    document.getElementById('btn-continue-creating').classList.add('hidden');
    document.getElementById('refinement-steps').innerHTML  = '';
    document.getElementById('execution-steps').innerHTML   = '';
    document.getElementById('disambiguation-card')?.remove();
    
    // Remove error banners
    document.getElementById('plan-failed-banner')?.remove();
    
    // Reset progress bar
    const progressBar = document.getElementById('progress-bar');
    const progressText = document.getElementById('progress-text');
    const progressStatus = document.getElementById('progress-status');
    const progressPercentage = document.getElementById('progress-percentage');
    
    if (progressBar) {
        progressBar.style.width = '0%';
        progressBar.className = 'bg-gradient-to-r from-forest-500 to-forest-600 h-3 rounded-full progress-bar-animated shadow-sm';
    }
    if (progressText) progressText.textContent = '0 / 0 steps';
    if (progressStatus) progressStatus.textContent = 'Preparing...';
    if (progressPercentage) progressPercentage.textContent = '0%';
    
    // Clear any active polling intervals
    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }

    setPhase('Ready to create');
    showProgress(false);
    document.getElementById('user-input').focus();
}

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

function buildFileAccept(inputTypes) {
    return inputTypes.map(t => {
        if (t === 'image') return 'image/*';
        if (t === 'video') return 'video/*';
        if (t === 'audio') return 'audio/*';
        return '*/*';
    }).join(',');
}

// ── Init ──────────────────────────────────────────────────────────────────────
// Background queue status poll (drives the jobs badge)
setInterval(async () => {
    try {
        const resp = await fetch(STUDIO_ROUTES.queueStatus);
        const data = await resp.json();
        updateQueueSummary(data);
    } catch(e) {}
}, 10000);