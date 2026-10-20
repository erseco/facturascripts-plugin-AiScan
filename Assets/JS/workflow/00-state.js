/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * GENERATED FILE: do not edit Assets/JS/aiscan-workflow.js by hand. Edit the
 * fragments in Assets/JS/workflow/ and run `make js` (issue #137).
 */

'use strict';

(() => {
    // ── State ──────────────────────────────────────────────────────────
    const state = {
        documents: [],
        currentIndex: 0,
        importMode: null,
        updateStockPurchaseData: false,
        codpago: null,
        partyType: 'supplier',
        useHistory: false,
        availableProviders: [],
        // Provider/model pairs the user can analyze with (#89)
        availableModels: [],
        defaultProvider: null,
        defaultModel: null,
        // Provider/model configured as the plugin default, for the badge (#89)
        configuredDefault: null,
        extractionPrompt: null,
        maxParallelRequests: 5,
        selectedIndices: new Set(),
        selectionAnchorIndex: null,
        sortField: 'upload_order',
        queueLocked: false,
        // Modo depuración / mock (sin IA)
        debugMode: !!(window.aiscanDebugMode),
        mockFixtures: Array.isArray(window.aiscanMockFixtures) ? window.aiscanMockFixtures.slice() : [],
        mockFixture: window.aiscanMockFixture || '',
        // Skip AI scan and open empty editable review panel (#67)
        manualEntryOnly: false,
    };

    const PARTY_SUPPLIER = 'supplier';
    const PARTY_CREDITOR = 'creditor';

    const STATUS = {
        PENDING: 'pending',
        ANALYZING: 'analyzing',
        ANALYZED: 'analyzed',
        NEEDS_REVIEW: 'needs_review',
        DISCARDED: 'discarded',
        READY: 'ready',
        IMPORTED: 'imported',
        FAILED: 'failed',
    };

    const STATUS_LABELS = {
        pending: {cls: 'text-bg-secondary', icon: 'fa-clock'},
        analyzing: {cls: 'text-bg-info', icon: 'fa-spinner fa-spin'},
        analyzed: {cls: 'text-bg-primary', icon: 'fa-check'},
        needs_review: {cls: 'text-bg-warning', icon: 'fa-exclamation-triangle'},
        discarded: {cls: 'text-bg-dark', icon: 'fa-ban'},
        ready: {cls: 'text-bg-success', icon: 'fa-check-circle'},
        imported: {cls: 'text-bg-success', icon: 'fa-file-invoice'},
        failed: {cls: 'text-bg-danger', icon: 'fa-times-circle'},
    };

    const MIN_AUTOCOMPLETE_QUERY_LENGTH = 2;
    const AUTOCOMPLETE_OPTION_SELECTOR = '[data-autocomplete-option]';

