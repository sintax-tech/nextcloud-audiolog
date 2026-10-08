/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Audiolog — recovery helpers shared by the main script.
 *
 * Kept free of DOM/IndexedDB so the Jest suite can load this exact file.
 * Exposed as window.AudiologRecovery (browser) and module.exports (Jest).
 */
(function(root) {
    'use strict';

    /**
     * Process a recording recovered from IndexedDB. The local copy is the
     * only copy of that audio: delete it only after the processing reported
     * success; on failure (HTTP 413, provider error, network...) keep it
     * recoverable.
     *
     * deps: { process: () => Promise<boolean>, deleteRecording(id), markUnsent(id) }
     * Returns true when processed (and deleted), false when kept.
     */
    async function processRecovered(id, deps) {
        let ok = false;
        try {
            ok = (await deps.process()) === true;
        } catch (_) {
            ok = false;
        }
        if (ok) {
            try { await deps.deleteRecording(id); } catch (_) { /* best effort */ }
            return true;
        }
        try { await deps.markUnsent(id); } catch (_) { /* best effort */ }
        return false;
    }

    const api = { processRecovered };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.AudiologRecovery = api;
    }
})(typeof window !== 'undefined' ? window : null);
