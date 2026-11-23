import { describe, expect, it } from 'vitest';
import { differenceClassName } from './month-status';

describe('differenceClassName', () => {
    it('reads a month ahead as success and one behind as muted, never red', () => {
        expect(differenceClassName(1500)).toBe('text-success');
        expect(differenceClassName(-1500)).toBe('text-muted-foreground');
        expect(differenceClassName(0)).toBe('');
    });
});
