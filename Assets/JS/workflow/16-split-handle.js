    // ── Split handle ───────────────────────────────────────────────────

    function bindSplitHandle() {
        // Preview / form split handle
        const handle = document.getElementById('aiscan-split-handle');
        const split = document.getElementById('aiscan-split');
        const left = document.getElementById('aiscan-split-left');
        if (handle && split && left) {
            bindResizeHandle(handle, split, left, () => {
                const rect = split.getBoundingClientRect();
                return (offset) => {
                    const pct = Math.min(Math.max((offset / rect.width) * 100, 25), 75);
                    left.style.flexBasis = pct + '%';
                };
            });
        }

        // Sidebar resize handle
        const sidebarHandle = document.getElementById('aiscan-sidebar-handle');
        const layout = document.getElementById('aiscan-review-layout');
        const sidebar = document.getElementById('aiscan-sidebar');
        if (sidebarHandle && layout && sidebar) {
            bindResizeHandle(sidebarHandle, layout, sidebar, () => {
                const rect = layout.getBoundingClientRect();
                return (offset) => {
                    const px = Math.min(Math.max(offset, 200), rect.width * 0.5);
                    sidebar.style.flexBasis = px + 'px';
                };
            });
        }
    }

    function bindResizeHandle(handle, container, target, makeResizer) {
        let dragging = false;
        let resizer = null;

        handle.addEventListener('mousedown', e => {
            e.preventDefault();
            dragging = true;
            resizer = makeResizer();
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
            container.classList.add('aiscan-split-dragging');
        });

        document.addEventListener('mousemove', e => {
            if (!dragging) {
                return;
            }
            const rect = container.getBoundingClientRect();
            const offset = e.clientX - rect.left;
            resizer(offset);
        });

        document.addEventListener('mouseup', () => {
            if (dragging) {
                dragging = false;
                resizer = null;
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
                container.classList.remove('aiscan-split-dragging');
            }
        });
    }

