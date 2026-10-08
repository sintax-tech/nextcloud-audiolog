/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Audiolog — pure text helpers shared by the main script.
 *
 * Kept free of DOM/OC dependencies so the Jest suite can load this exact file.
 * Exposed as window.AudiologTextUtils (browser) and module.exports (Jest).
 */
(function(root) {
    'use strict';

    // Find longest overlap where suffix of `prev` equals prefix of `next`,
    // matching whole words only. Returns chars consumed from `next`.
    function findTextOverlap(prev, next) {
        if (!prev || !next) return 0;
        const prevTail = prev.slice(-80).toLowerCase();
        const nextHead = next.slice(0, 80).toLowerCase();
        const max = Math.min(prevTail.length, nextHead.length);
        for (let len = max; len >= 4; len--) {
            if (prevTail.slice(-len) === nextHead.slice(0, len)) {
                // Snap to a word boundary so we don't chop mid-word.
                const boundary = next.slice(0, len).search(/\s\S*$/);
                return boundary > 0 ? boundary : len;
            }
        }
        return 0;
    }

    const api = { findTextOverlap };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.AudiologTextUtils = api;
    }
})(typeof window !== 'undefined' ? window : null);
