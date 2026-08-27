import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ImportStepUpload, isSupportedImportFile } from './import-step-upload';

describe('ImportStepUpload', () => {
    it('hides the back button when requested', () => {
        render(
            <ImportStepUpload
                file={null}
                onFileSelect={vi.fn()}
                onNext={vi.fn()}
                onBack={vi.fn()}
                showBackButton={false}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Back' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Next' })).not.toBeNull();
    });

    it('offers every supported extension to the file picker', () => {
        const { container } = render(
            <ImportStepUpload
                file={null}
                onFileSelect={vi.fn()}
                onNext={vi.fn()}
                onBack={vi.fn()}
            />,
        );

        const input = container.querySelector('input[type="file"]');

        expect(input?.getAttribute('accept')).toBe('.csv,.xls,.xlsx,.numbers');
    });
});

describe('isSupportedImportFile', () => {
    const fileNamed = (name: string) => new File(['x'], name);

    it.each([
        'budget.csv',
        'budget.xls',
        'budget.xlsx',
        'budget.numbers',
        'Copia de monefy_whisper_import.numbers',
        'BUDGET.NUMBERS',
        'export.2026.numbers',
    ])('accepts %s', (name) => {
        expect(isSupportedImportFile(fileNamed(name))).toBe(true);
    });

    it.each([
        'budget.pages',
        'budget.txt',
        'budget.pdf',
        'budget.numbers.zip',
        'numbers',
        'budget',
    ])('rejects %s', (name) => {
        expect(isSupportedImportFile(fileNamed(name))).toBe(false);
    });

    it('rejects a missing file', () => {
        expect(isSupportedImportFile(null)).toBe(false);
        expect(isSupportedImportFile(undefined)).toBe(false);
    });
});
