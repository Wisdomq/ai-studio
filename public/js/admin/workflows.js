// ── Admin Workflows Page ─────────────────────────────────────────────────────
// Handles search, filtering, and workflow management

let currentFilters = {
    search: '',
    outputType: 'all',
    capability: 'all',
    source: 'all',
    status: 'all'
};

// ── Initialize ────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    initializeFilters();
    updateResultsCount();
});

function initializeFilters() {
    const searchInput = document.getElementById('search-workflows');
    const filterOutputType = document.getElementById('filter-output-type');
    const filterCapability = document.getElementById('filter-capability');
    const filterSource = document.getElementById('filter-source');
    const filterStatus = document.getElementById('filter-status');

    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            currentFilters.search = e.target.value.toLowerCase().trim();
            applyFilters();
        });
    }

    if (filterOutputType) {
        filterOutputType.addEventListener('change', (e) => {
            currentFilters.outputType = e.target.value;
            applyFilters();
        });
    }

    if (filterCapability) {
        filterCapability.addEventListener('change', (e) => {
            currentFilters.capability = e.target.value;
            applyFilters();
        });
    }

    if (filterSource) {
        filterSource.addEventListener('change', (e) => {
            currentFilters.source = e.target.value;
            applyFilters();
        });
    }

    if (filterStatus) {
        filterStatus.addEventListener('change', (e) => {
            currentFilters.status = e.target.value;
            applyFilters();
        });
    }
}

// ── Filtering ─────────────────────────────────────────────────────────────────

function applyFilters() {
    const rows = document.querySelectorAll('tbody tr[id^="wf-row-"]');
    let visibleCount = 0;

    rows.forEach(row => {
        const name = row.dataset.name?.toLowerCase() || '';
        const description = row.dataset.description?.toLowerCase() || '';
        const outputType = row.dataset.outputType || '';
        const capabilities = row.dataset.capabilities || '';
        const mcpId = row.dataset.mcpId || '';
        const isActive = row.querySelector('.btn-toggle')?.classList.contains('bg-forest-500');

        let visible = true;

        // Apply search filter
        if (currentFilters.search) {
            const searchMatch = name.includes(currentFilters.search) || 
                              description.includes(currentFilters.search);
            if (!searchMatch) visible = false;
        }

        // Apply output type filter
        if (currentFilters.outputType !== 'all' && outputType !== currentFilters.outputType) {
            visible = false;
        }

        // Apply capability filter
        if (currentFilters.capability !== 'all') {
            const capabilitySlugs = capabilities.split(',').filter(c => c.length > 0);
            if (!capabilitySlugs.includes(currentFilters.capability)) {
                visible = false;
            }
        }

        // Apply source filter
        if (currentFilters.source !== 'all') {
            if (currentFilters.source === 'mcp' && !mcpId) visible = false;
            if (currentFilters.source === 'db' && mcpId) visible = false;
        }

        // Apply status filter
        if (currentFilters.status !== 'all') {
            if (currentFilters.status === 'active' && !isActive) visible = false;
            if (currentFilters.status === 'inactive' && isActive) visible = false;
        }

        // Show/hide row
        if (visible) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    updateResultsCount(visibleCount);
}

function updateResultsCount(count) {
    const countEl = document.getElementById('results-count');
    if (!countEl) return;

    if (count === undefined) {
        // Initial count - count all visible rows
        const rows = document.querySelectorAll('tbody tr[id^="wf-row-"]');
        count = Array.from(rows).filter(row => row.style.display !== 'none').length;
    }

    const plural = count !== 1 ? 's' : '';
    countEl.textContent = `${count} workflow${plural}`;
}
