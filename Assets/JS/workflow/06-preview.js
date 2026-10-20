    // ── Navigation & Rendering ─────────────────────────────────────────

    function navigateTo(index) {
        if (index < 0 || index >= state.documents.length) {
            return;
        }
        persistCurrentFormState();
        state.currentIndex = index;
        renderCurrentDocument();
    }

    function renderCurrentDocument() {
        const doc = currentDoc();
        if (!doc) {
            return;
        }

        renderPreview(doc);
        renderReviewPanel(doc);
        renderSidebar();
    }

    function nextRotation(current, delta) {
        return (((current || 0) + delta) % 360 + 360) % 360;
    }

    /**
     * Draws the preview image rotated on a canvas, always from the original
     * file, so the zoom and pan keep working on the rotated image (#117).
     */
    function renderRotatedImage(img, doc) {
        const deg = doc.previewRotation || 0;
        if (!deg) {
            img.src = doc.objectUrl;
            return;
        }
        const source = new Image();
        source.onload = () => {
            const sideways = deg % 180 !== 0;
            const canvas = document.createElement('canvas');
            canvas.width = sideways ? source.naturalHeight : source.naturalWidth;
            canvas.height = sideways ? source.naturalWidth : source.naturalHeight;
            const ctx = canvas.getContext('2d');
            ctx.translate(canvas.width / 2, canvas.height / 2);
            ctx.rotate(deg * Math.PI / 180);
            ctx.drawImage(source, -source.naturalWidth / 2, -source.naturalHeight / 2);
            img.src = canvas.toDataURL();
        };
        source.src = doc.objectUrl;
    }

    function renderPreview(doc) {
        const area = document.getElementById('aiscan-preview-area');
        const title = document.getElementById('aiscan-preview-title');
        if (!area) {
            return;
        }

        title.textContent = doc.originalName;
        area.innerHTML = '';

        if (previewAsPdf(doc) && doc.objectUrl) {
            area.innerHTML = `<iframe src="${doc.objectUrl}#navpanes=0&toolbar=1&scrollbar=1" title="${escapeAttr(doc.originalName)}"></iframe>`;
        } else if (doc.objectUrl) {
            area.style.position = 'relative';
            area.innerHTML = `
                <div class="aiscan-img-container" style="height:100%;overflow:auto;cursor:grab">
                    <img src="${doc.objectUrl}" alt="${escapeAttr(doc.originalName)}" style="transform-origin:top left;transition:transform 0.2s" class="aiscan-zoom-img">
                </div>
                <div class="position-absolute bottom-0 end-0 m-2 d-flex gap-1" style="z-index:2">
                    <button class="btn btn-sm btn-light shadow-sm aiscan-rotate-left" title="${escapeAttr(trans('aiscan-rotate-left'))}"><i class="fa-solid fa-rotate-left"></i></button>
                    <button class="btn btn-sm btn-light shadow-sm aiscan-rotate-right" title="${escapeAttr(trans('aiscan-rotate-right'))}"><i class="fa-solid fa-rotate-right"></i></button>
                    <button class="btn btn-sm btn-light shadow-sm aiscan-zoom-out" title="Zoom -"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
                    <button class="btn btn-sm btn-light shadow-sm aiscan-zoom-reset" title="Reset"><i class="fa-solid fa-expand"></i></button>
                    <button class="btn btn-sm btn-light shadow-sm aiscan-zoom-in" title="Zoom +"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
                </div>`;
            let zoomLevel = 1;
            const img = area.querySelector('.aiscan-zoom-img');
            const container = area.querySelector('.aiscan-img-container');
            area.querySelector('.aiscan-zoom-in')?.addEventListener('click', () => {
                zoomLevel = Math.min(zoomLevel + 0.25, 4);
                img.style.transform = `scale(${zoomLevel})`;
            });
            area.querySelector('.aiscan-zoom-out')?.addEventListener('click', () => {
                zoomLevel = Math.max(zoomLevel - 0.25, 0.5);
                img.style.transform = `scale(${zoomLevel})`;
            });
            area.querySelector('.aiscan-zoom-reset')?.addEventListener('click', () => {
                zoomLevel = 1;
                img.style.transform = 'scale(1)';
            });
            // Rotate upside-down or sideways photos for review (#117).
            renderRotatedImage(img, doc);
            area.querySelector('.aiscan-rotate-left')?.addEventListener('click', () => {
                doc.previewRotation = nextRotation(doc.previewRotation, -90);
                renderRotatedImage(img, doc);
            });
            area.querySelector('.aiscan-rotate-right')?.addEventListener('click', () => {
                doc.previewRotation = nextRotation(doc.previewRotation, 90);
                renderRotatedImage(img, doc);
            });
            // Drag to pan
            let isDragging = false;
            let startX = 0;
            let startY = 0;
            let scrollLeft = 0;
            let scrollTop = 0;
            container.addEventListener('mousedown', e => {
                isDragging = true;
                container.style.cursor = 'grabbing';
                startX = e.pageX - container.offsetLeft;
                startY = e.pageY - container.offsetTop;
                scrollLeft = container.scrollLeft;
                scrollTop = container.scrollTop;
                e.preventDefault();
            });
            container.addEventListener('mousemove', e => {
                if (!isDragging) {
                    return;
                }
                const x = e.pageX - container.offsetLeft;
                const y = e.pageY - container.offsetTop;
                container.scrollLeft = scrollLeft - (x - startX);
                container.scrollTop = scrollTop - (y - startY);
            });
            container.addEventListener('mouseup', () => {
                isDragging = false;
                container.style.cursor = 'grab';
            });
            container.addEventListener('mouseleave', () => {
                isDragging = false;
                container.style.cursor = 'grab';
            });
        } else {
            area.innerHTML = `<p class="text-muted text-center p-4">${escapeHtml(trans('aiscan-no-preview'))}</p>`;
        }

        const titleEl = document.getElementById('aiscan-preview-title');
        if (titleEl) {
            titleEl.textContent = doc.originalName;
            titleEl.title = doc.originalName;
        }

        renderReanalyzeMenu(doc);

        const statusEl = document.getElementById('aiscan-status');
        const jsonBtn = doc.extractedData
            ? ` <button class="btn btn-outline-secondary btn-sm py-0 px-1 ms-1 aiscan-debug-json-btn" type="button" title="JSON"><i class="fa-solid fa-code"></i></button>`
            : '';
        if (doc.error && !doc.extractedData) {
            statusEl.innerHTML = `<div class="alert alert-danger py-1 px-2 small mb-0">${escapeHtml(doc.error)}${jsonBtn}</div>`;
        } else if (doc.scanFailed && doc.extractedData) {
            const msg = doc.scanFailedMessage || trans('aiscan-scan-failed-manual-entry');
            statusEl.innerHTML = `<div class="alert alert-warning py-1 px-2 small mb-0 d-flex align-items-center justify-content-between"><span><i class="fa-solid fa-triangle-exclamation me-1"></i>${escapeHtml(msg)}</span>${jsonBtn}</div>`;
        } else if (doc.status === STATUS.ANALYZING) {
            statusEl.innerHTML = `<div class="alert alert-info py-1 px-2 small mb-0"><i class="fa-solid fa-spinner fa-spin me-1"></i>${escapeHtml(trans('aiscan-analysis-started', {'%provider%': choiceLabel(modelChoiceForDoc(doc))}))}</div>`;
        } else if (doc.status === STATUS.ANALYZED || doc.status === STATUS.READY || doc.status === STATUS.NEEDS_REVIEW) {
            statusEl.innerHTML = `<div class="alert alert-success py-1 px-2 small mb-0 d-flex align-items-center justify-content-between">${escapeHtml(trans('aiscan-analysis-completed', {'%provider%': choiceLabel(modelChoiceForDoc(doc))}))}${jsonBtn}</div>`;
        } else if (doc.status === STATUS.DISCARDED) {
            statusEl.innerHTML = `<div class="alert alert-dark py-1 px-2 small mb-0"><i class="fa-solid fa-ban me-1"></i>${escapeHtml(trans('aiscan-doc-discarded'))}</div>`;
        } else {
            statusEl.innerHTML = '';
        }

        // Bind JSON debug button
        const debugBtn = statusEl.querySelector('.aiscan-debug-json-btn');
        if (debugBtn) {
            debugBtn.addEventListener('click', () => {
                showJsonDebugModal(doc.extractedData);
            });
        }

        const stateBadge = document.getElementById('aiscan-doc-state-badge');
        if (stateBadge) {
            const info = STATUS_LABELS[doc.status] || STATUS_LABELS.pending;
            stateBadge.className = `badge ${info.cls}`;
            stateBadge.innerHTML = `<i class="fa-solid ${info.icon} me-1"></i>${escapeHtml(trans('aiscan-status-' + doc.status))}`;
        }
    }

