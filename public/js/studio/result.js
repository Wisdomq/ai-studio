// ── AI Studio — Result Page ──────────────────────────────────────────────────
// Handles lightbox, details toggle, and download all functionality

// ── Toggle Step Details ───────────────────────────────────────────────────────

function toggleDetails(stepId) {
    const details = document.getElementById(`details-${stepId}`);
    if (!details) return;
    
    details.classList.toggle('hidden');
}

// ── Lightbox ──────────────────────────────────────────────────────────────────

function openLightbox(url, type) {
    const lightbox = document.getElementById('lightbox');
    const content = document.getElementById('lightbox-content');
    
    if (!lightbox || !content) return;
    
    if (type === 'image') {
        content.innerHTML = `<img src="${url}" class="max-w-full max-h-full object-contain rounded-lg" alt="Full size preview">`;
    } else if (type === 'video') {
        content.innerHTML = `<video controls autoplay class="max-w-full max-h-full rounded-lg" src="${url}"></video>`;
    }
    
    lightbox.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    const lightbox = document.getElementById('lightbox');
    const content = document.getElementById('lightbox-content');
    
    if (!lightbox || !content) return;
    
    lightbox.classList.add('hidden');
    content.innerHTML = '';
    document.body.style.overflow = '';
}

// Close lightbox on Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeLightbox();
    }
});

// ── Download All ──────────────────────────────────────────────────────────────

function downloadAll() {
    const downloadLinks = document.querySelectorAll('a[download]');
    
    if (downloadLinks.length === 0) {
        alert('No files available to download');
        return;
    }
    
    // Download each file with a small delay to avoid browser blocking
    downloadLinks.forEach((link, index) => {
        setTimeout(() => {
            link.click();
        }, index * 500);
    });
}
