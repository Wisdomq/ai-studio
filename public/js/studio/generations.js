// ── AI Studio — Generations Page ─────────────────────────────────────────────
// Handles filtering, sorting, and view toggle for the My Generations page

let currentView = 'grid';
let currentFilters = {
    search: '',
    type: 'all',
    sort: 'newest'
};

// ── Initialize ────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    initializeFilters();
    updateResultsCount();
});

function initializeFilters() {
    const searchInput = document.getElementById('search-input');
    const filterType = document.getElementById('filter-type');
    const sortBy = document.getElementById('sort-by');

    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            currentFilters.search = e.target.value.toLowerCase().trim();
            applyFilters();
        });
    }

    if (filterType) {
        filterType.addEventListener('change', (e) => {
            currentFilters.type = e.target.value;
            applyFilters();
        });
    }

    if (sortBy) {
        sortBy.addEventListener('change', (e) => {
            currentFilters.sort = e.target.value;
            applyFilters();
        });
    }
}

// ── View Toggle ───────────────────────────────────────────────────────────────

function setView(view) {
    currentView = view;
    const grid = document.getElementById('generations-grid');
    const gridBtn = document.getElementById('view-grid');
    const listBtn = document.getElementById('view-list');

    if (!grid) return;

    if (view === 'grid') {
        grid.className = 'grid grid-cols-2 md:grid-cols-3 gap-4';
        gridBtn.classList.add('active');
        listBtn.classList.remove('active');
    } else {
        grid.className = 'space-y-3';
        gridBtn.classList.remove('active');
        listBtn.classList.add('active');
    }

    // Update item layouts
    const items = document.querySelectorAll('.generation-item');
    items.forEach(item => {
        const link = item.querySelector('a');
        if (!link) return;

        if (view === 'list') {
            // List view: horizontal layout
            link.className = 'group bg-white border-2 border-gray-200 rounded-2xl overflow-hidden hover:border-forest-400 hover:shadow-lg transition-all duration-200 flex items-center gap-4';
            
            const mediaContainer = link.querySelector('.aspect-square');
            if (mediaContainer) {
                mediaContainer.className = 'w-32 h-32 bg-gray-50 flex items-center justify-center overflow-hidden relative shrink-0';
            }
        } else {
            // Grid view: vertical layout
            link.className = 'group bg-white border-2 border-gray-200 rounded-2xl overflow-hidden hover:border-forest-400 hover:shadow-lg transition-all duration-200 block';
            
            const mediaContainer = link.querySelector('.w-32');
            if (mediaContainer) {
                mediaContainer.className = 'aspect-square bg-gray-50 flex items-center justify-center overflow-hidden relative';
            }
        }
    });
}

// ── Filtering & Sorting ───────────────────────────────────────────────────────

function applyFilters() {
    const items = document.querySelectorAll('.generation-item');
    let visibleCount = 0;

    // Convert items to array for sorting
    const itemsArray = Array.from(items);

    // First, filter items
    itemsArray.forEach(item => {
        const type = item.dataset.type;
        const title = item.dataset.title;
        
        let visible = true;

        // Apply type filter
        if (currentFilters.type !== 'all' && type !== currentFilters.type) {
            visible = false;
        }

        // Apply search filter
        if (currentFilters.search && !title.includes(currentFilters.search)) {
            visible = false;
        }

        // Show/hide with smooth transition
        if (visible) {
            item.style.display = '';
            item.classList.add('fade-in');
            visibleCount++;
        } else {
            item.style.display = 'none';
            item.classList.remove('fade-in');
        }
    });

    // Then, sort visible items
    const visibleItems = itemsArray.filter(item => item.style.display !== 'none');
    sortItems(visibleItems);

    // Update results count
    updateResultsCount(visibleCount);
}

function sortItems(items) {
    if (items.length === 0) return;

    const grid = document.getElementById('generations-grid');
    if (!grid) return;

    // Sort based on current sort option
    items.sort((a, b) => {
        const dateA = parseInt(a.dataset.date);
        const dateB = parseInt(b.dataset.date);

        if (currentFilters.sort === 'newest') {
            return dateB - dateA; // Newest first
        } else {
            return dateA - dateB; // Oldest first
        }
    });

    // Reorder DOM elements
    items.forEach(item => {
        grid.appendChild(item);
    });
}

function updateResultsCount(count) {
    const countEl = document.getElementById('results-count');
    if (!countEl) return;

    if (count === undefined) {
        // Initial count - count all visible items
        const items = document.querySelectorAll('.generation-item');
        count = Array.from(items).filter(item => item.style.display !== 'none').length;
    }

    const plural = count !== 1 ? 's' : '';
    countEl.textContent = `${count} generation${plural}`;
}
