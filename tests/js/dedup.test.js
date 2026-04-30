/**
 * Tests for the suffix/prefix dedup helper used by the live transcription
 * paths. Extracted as a standalone module here so we can test it without
 * the rest of the live recognition machinery.
 */

// Mirrored implementation of `findTextOverlap` from audiolog-main.js — kept
// here so the test stays self-contained. If you change one, change both.
function findTextOverlap(prev, next) {
    if (!prev || !next) return 0;
    const prevTail = prev.slice(-80).toLowerCase();
    const nextHead = next.slice(0, 80).toLowerCase();
    const max = Math.min(prevTail.length, nextHead.length);
    for (let len = max; len >= 4; len--) {
        if (prevTail.slice(-len) === nextHead.slice(0, len)) {
            const boundary = next.slice(0, len).search(/\s\S*$/);
            return boundary > 0 ? boundary : len;
        }
    }
    return 0;
}

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
