/**
 * Tests for the suffix/prefix dedup helper used by the live transcription
 * paths. The helper lives in js/audiolog-text-utils.js, the same file the
 * page loads, so this tests the shipped code.
 */

const { findTextOverlap } = require('../../js/audiolog-text-utils.js');

describe('findTextOverlap', () => {
    it('returns 0 for empty inputs', () => {
        expect(findTextOverlap('', 'foo')).toBe(0);
        expect(findTextOverlap('foo', '')).toBe(0);
    });

    it('returns 0 when there is no overlap', () => {
        expect(findTextOverlap('hello world', 'completely different')).toBe(0);
    });

    it('detects a clean tail-head match', () => {
        const prev = 'the quick brown fox jumps over the lazy';
        const next = 'over the lazy dog and runs away';
        const skip = findTextOverlap(prev, next);
        expect(skip).toBeGreaterThan(0);
        // The non-skipped slice should still contain the new content.
        expect(next.slice(skip)).toContain('dog');
    });

    it('snaps to a word boundary instead of chopping mid-word', () => {
        const prev = 'i was talking about budget';
        const next = 'budget planning for the next quarter';
        const skip = findTextOverlap(prev, next);
        // We should land at a whitespace, not in the middle of "budget".
        expect(next.slice(skip).startsWith(' ') || next.slice(skip).startsWith('p')).toBe(true);
        expect(next.slice(skip)).toContain('planning');
    });

    it('ignores overlaps shorter than the 4-char minimum', () => {
        // Both end/start with "to" — only 2 chars — should NOT trigger dedup.
        expect(findTextOverlap('went to', 'to school')).toBe(0);
    });
});
