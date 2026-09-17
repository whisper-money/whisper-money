import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { describeRows, StepImportPartial } from './step-import-partial';

describe('describeRows', () => {
    it('names a single row', () => {
        expect(describeRows([44])).toBe('Row 44');
    });

    // A block of neighbours is one stretch of the spreadsheet to go and look at.
    it('turns a run of neighbours into a range', () => {
        expect(describeRows([44, 45, 46])).toBe('Rows 44–46');
    });

    it('names scattered rows one by one', () => {
        expect(describeRows([112, 140, 203])).toBe('Rows 112, 140, 203');
    });

    it('stops naming rows once the naming stops helping', () => {
        expect(describeRows([1, 3, 5, 7, 9])).toBe('Rows 1, 3, 5 and 2 more');
    });

    it('reads the same however the rows arrived', () => {
        expect(describeRows([46, 44, 45])).toBe('Rows 44–46');
    });
});

describe('StepImportPartial', () => {
    const failures = [
        { rowNumber: 44, reason: 'No date' },
        { rowNumber: 45, reason: 'No date' },
        { rowNumber: 112, reason: 'Amount was empty' },
    ];

    it('groups the rows that failed the same way', () => {
        render(
            <StepImportPartial
                importedCount={281}
                failures={failures}
                onContinue={vi.fn()}
                onRetry={vi.fn()}
            />,
        );

        expect(screen.getByText('2 rows')).toBeTruthy();
        expect(screen.getByText('Rows 44–45')).toBeTruthy();
        expect(screen.getByText('1 row')).toBeTruthy();
        expect(screen.getByText('Row 112')).toBeTruthy();
    });

    it('offers to carry on with what made it in', () => {
        render(
            <StepImportPartial
                importedCount={281}
                failures={failures}
                onContinue={vi.fn()}
                onRetry={vi.fn()}
            />,
        );

        expect(screen.getByText("281 in, 3 we couldn't read")).toBeTruthy();
        expect(screen.getByText('Continue with 281')).toBeTruthy();
    });

    // With nothing saved there is nothing to carry on with, and offering it
    // would move the user past a step that did not happen.
    it('offers only the retry when nothing made it in', () => {
        render(
            <StepImportPartial
                importedCount={0}
                failures={failures}
                onContinue={vi.fn()}
                onRetry={vi.fn()}
            />,
        );

        expect(screen.queryByText('Continue with 0')).toBeNull();
        expect(screen.getByText('Fix the file and retry')).toBeTruthy();
    });
});
