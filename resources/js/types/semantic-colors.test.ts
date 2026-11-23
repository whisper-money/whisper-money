import { describe, expect, it } from 'vitest';
import { getBudgetSeverityColor } from './budget';
import { getSavingsGoalStatusColor } from './savings-goal';

/** The color policy in docs/design-system.md (#1358). */
describe('finance status colors', () => {
    it('warns about a budget over or near its limit, never in red', () => {
        expect(getBudgetSeverityColor('over')).toBe('text-warning');
        expect(getBudgetSeverityColor('near')).toBe('text-warning/70');
        expect(getBudgetSeverityColor('ok')).toBe('text-success');
    });

    it('warns about a savings goal behind schedule', () => {
        expect(getSavingsGoalStatusColor('ahead')).toBe('text-success');
        expect(getSavingsGoalStatusColor('on_track')).toBe('text-success');
        expect(getSavingsGoalStatusColor('completed')).toBe('text-success');
        expect(getSavingsGoalStatusColor('behind')).toBe('text-warning');
    });
});
