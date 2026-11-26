import {
    accountMonths,
    editedDefinitions,
    estimateDeltas,
    evaluateAlert,
    impactOn,
    withEstimateDeltas,
    withLine,
} from '@/lib/forecast/edits';
import {
    type ExpandableForecastItem,
    type ProjectionInput,
} from '@/lib/forecast/types';
import { type ForecastGroup } from '@/types/forecast';
import { describe, expect, it } from 'vitest';

const rent: ExpandableForecastItem = {
    id: 'rent',
    accountId: 'checking',
    destinationAccountId: null,
    frequency: 'monthly',
    anchorDate: '2026-01-05',
    endsOn: null,
    versions: [{ effectiveFrom: '2026-01-05', amount: -190000 }],
    overrides: [],
};

const salary: ExpandableForecastItem = {
    ...rent,
    id: 'salary',
    anchorDate: '2026-01-01',
    versions: [{ effectiveFrom: '2026-01-01', amount: 300000 }],
};

const insurance: ExpandableForecastItem = {
    ...rent,
    id: 'insurance',
    frequency: 'yearly',
    anchorDate: '2027-04-03',
    versions: [{ effectiveFrom: '2027-04-03', amount: -705000 }],
};

/** 1,000 in checking on 26 Nov 2026: April 2027 drops to −550. */
function input(): ProjectionInput {
    return withLine(
        withLine(
            withLine(
                {
                    accounts: [
                        { id: 'checking', openingBalance: 100000 },
                        { id: 'savings', openingBalance: 900000 },
                    ],
                    occurrences: [],
                    estimates: [],
                    startDate: '2026-11-26',
                    horizonMonths: 7,
                },
                'rent',
                [rent],
            ),
            'salary',
            [salary],
        ),
        'insurance',
        [insurance],
    );
}

const edit = {
    scope: 'from_here' as const,
    occurrenceDate: '2027-03-05',
    amount: null,
    frequency: null,
    day: null,
};

describe('editing a line on the client', () => {
    it('stores "only this month" as an override of that occurrence', () => {
        const [line] = editedDefinitions(
            rent,
            { ...edit, scope: 'occurrence', amount: -210000, day: 31 },
            '2026-11-26',
        );

        expect(line.overrides).toEqual([
            {
                occurrenceDate: '2027-03-05',
                amount: -210000,
                movedTo: '2027-03-31',
                skipped: false,
            },
        ]);
        expect(line.versions).toEqual(rent.versions);
    });

    it('adds a version for "from this month on"', () => {
        const lines = editedDefinitions(
            rent,
            { ...edit, amount: -200000 },
            '2026-11-26',
        );

        expect(lines).toHaveLength(1);
        expect(lines[0].versions).toEqual([
            { effectiveFrom: '2026-01-05', amount: -190000 },
            { effectiveFrom: '2027-03-05', amount: -200000 },
        ]);
    });

    it('splits the line when a new day starts after months still to come', () => {
        const [before, after] = editedDefinitions(
            rent,
            { ...edit, day: 1 },
            '2026-11-26',
        );

        expect(before.endsOn).toBe('2027-03-04');
        expect(after).toMatchObject({
            id: 'rent:next',
            anchorDate: '2027-03-01',
            versions: [{ effectiveFrom: '2027-03-01', amount: -190000 }],
        });
    });

    it('reschedules in place when nothing of the line came before', () => {
        const lines = editedDefinitions(
            insurance,
            {
                ...edit,
                occurrenceDate: '2027-04-03',
                frequency: 'quarterly',
                day: 20,
            },
            '2026-11-26',
        );

        expect(lines).toEqual([
            {
                ...insurance,
                anchorDate: '2027-04-20',
                frequency: 'quarterly',
                overrides: [],
            },
        ]);
    });
});

describe('recomputing the forecast', () => {
    it('lifts the warning once a transfer covers the dip, and says by how much', () => {
        const before = accountMonths(input(), 'checking');
        const withTransfer = withLine(input(), 'new-line', [
            {
                id: 'new-line',
                accountId: 'savings',
                destinationAccountId: 'checking',
                frequency: 'once',
                anchorDate: '2027-04-01',
                versions: [{ effectiveFrom: '2027-04-01', amount: -60000 }],
            },
        ]);
        const after = accountMonths(withTransfer, 'checking');

        expect(evaluateAlert(before, 0)).toMatchObject({
            level: 'strong',
            month: '2027-04',
            min: -55000,
        });
        expect(evaluateAlert(after, 0).level).toBe('ok');
        expect(impactOn(before, after)).toEqual({
            month: '2027-04',
            before: -55000,
            after: 5000,
        });
        expect(accountMonths(withTransfer, 'savings').at(-1)?.close).toBe(
            840000,
        );
    });

    it('moves the estimated rest of a group by what the adjustment changes', () => {
        const group: ForecastGroup = {
            key: 'food',
            categoryId: 'food',
            name: 'Food',
            icon: null,
            color: null,
            items: [],
            estimate: {
                basis: 'median',
                budgetAmount: null,
                amounts: { '2026-12': -30000, '2027-01': -30000 },
                current: { spent: 10000, planned: 30000, left: 20000 },
            },
        };
        const months = ['2026-11', '2026-12', '2027-01'];

        expect(
            estimateDeltas(group, 45000, '2026-11', 'from_here', months),
        ).toEqual({ '2026-11': 15000, '2026-12': 15000, '2027-01': 15000 });
        expect(
            estimateDeltas(group, 20000, '2026-12', 'month', months),
        ).toEqual({ '2026-12': -10000 });

        const adjusted = withEstimateDeltas(input(), 'checking', {
            '2026-12': 15000,
        });

        expect(adjusted.estimates).toEqual([
            { accountId: 'checking', month: '2026-12', amount: 15000 },
        ]);
        expect(accountMonths(adjusted, 'checking')[1].close).toBe(
            accountMonths(input(), 'checking')[1].close - 15000,
        );
    });
});
