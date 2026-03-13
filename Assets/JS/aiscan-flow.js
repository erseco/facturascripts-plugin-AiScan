(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
        return;
    }

    root.AiScanFlow = factory();
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    function normalizeUploadResponse(response) {
        if (response && Array.isArray(response.files) && response.files.length > 0) {
            return response.files.map(cloneValue);
        }

        if (response && response.tmp_file) {
            return [cloneValue({
                client_index: response.client_index ?? 0,
                mime_type: response.mime_type,
                original_name: response.original_name,
                size: response.size,
                tmp_file: response.tmp_file,
            })];
        }

        return [];
    }

    function createWizardEntries(uploadedFiles, invoiceId) {
        return (Array.isArray(uploadedFiles) ? uploadedFiles : []).map((file, index) => ({
            clientIndex: Number.isInteger(file.client_index) ? file.client_index : index,
            extractedData: null,
            file: null,
            invoiceId: index === 0 ? (invoiceId || '') : '',
            isSaved: false,
            isSaving: false,
            mimeType: file.mime_type || '',
            objectUrl: null,
            originalName: file.original_name || '',
            selectedProvider: '',
            size: file.size || 0,
            tmpFile: file.tmp_file || '',
        }));
    }

    function getWizardMeta(entries, currentIndex) {
        const total = Array.isArray(entries) ? entries.length : 0;
        const safeIndex = total === 0 ? 0 : Math.min(Math.max(currentIndex || 0, 0), total - 1);
        const isMultiFile = total > 1;
        const isLast = total > 0 ? safeIndex === total - 1 : true;

        return {
            canGoBack: isMultiFile && safeIndex > 0,
            canGoNext: isMultiFile && safeIndex < total - 1,
            currentIndex: safeIndex,
            currentPosition: total === 0 ? 0 : safeIndex + 1,
            isLast,
            isMultiFile,
            primaryAction: isMultiFile ? (isLast ? 'finish' : 'saveAndNext') : 'save',
            total,
        };
    }

    function getSaveInvoiceId(entry) {
        return entry && entry.invoiceId ? String(entry.invoiceId) : '';
    }

    function markEntrySaved(entry, invoiceId) {
        return {
            ...entry,
            invoiceId: invoiceId || getSaveInvoiceId(entry),
            isSaved: true,
            isSaving: false,
        };
    }

    function cloneValue(value) {
        if (value == null) {
            return value;
        }

        return JSON.parse(JSON.stringify(value));
    }

    return {
        cloneValue,
        createWizardEntries,
        getSaveInvoiceId,
        getWizardMeta,
        markEntrySaved,
        normalizeUploadResponse,
    };
});
