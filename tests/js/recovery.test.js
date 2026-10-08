/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * N1: "Process now" on the recovery banner must delete the IndexedDB copy
 * only after the processing succeeded; on failure the recording stays
 * recoverable ('interrupted').
 */
const { processRecovered } = require('../../js/audiolog-recovery.js');

function deps(processResult) {
    const calls = [];
    return {
        calls,
        process: async () => {
            calls.push('process');
            if (processResult instanceof Error) throw processResult;
            return processResult;
        },
        deleteRecording: async (id) => { calls.push('delete:' + id); },
        markUnsent: async (id) => { calls.push('keep:' + id); },
    };
}

describe('processRecovered', () => {
    it('deletes the local copy only after a successful processing', async () => {
        const d = deps(true);
        await expect(processRecovered('r1', d)).resolves.toBe(true);
        expect(d.calls).toEqual(['process', 'delete:r1']);
    });

    it('keeps the recording when processing reports failure', async () => {
        const d = deps(false);
        await expect(processRecovered('r1', d)).resolves.toBe(false);
        expect(d.calls).toEqual(['process', 'keep:r1']);
    });

    it('keeps the recording when processing throws', async () => {
        const d = deps(new Error('HTTP 413'));
        await expect(processRecovered('r1', d)).resolves.toBe(false);
        expect(d.calls).toEqual(['process', 'keep:r1']);
    });

    it('never deletes before processing finished', async () => {
        let release;
        const d = deps(true);
        d.process = () => new Promise((resolve) => { release = resolve; d.calls.push('process'); });
        const p = processRecovered('r1', d);
        await Promise.resolve();
        expect(d.calls).toEqual(['process']);
        release(true);
        await p;
        expect(d.calls).toEqual(['process', 'delete:r1']);
    });
});
