    // ── Initialization ─────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        const providerSelect = document.getElementById('aiscan-provider-select');
        if (providerSelect && providerSelect.value) {
            state.availableModels = Array.from(providerSelect.options)
                .filter(o => o.value)
                .map(o => ({
                    provider: o.value,
                    model: o.dataset ? (o.dataset.model || '') : '',
                    label: o.textContent,
                }));
            state.availableProviders = state.availableModels
                .map(c => c.provider)
                .filter((p, i, all) => all.indexOf(p) === i);
            const initial = selectedModelChoice();
            state.defaultProvider = initial.provider;
            state.defaultModel = initial.model;
            // The view preselects the configured default, so this is it.
            state.configuredDefault = initial;
        }
        bindUploadStep();
        bindReviewStep();
        bindImportStep();
        bindSplitHandle();
    }

