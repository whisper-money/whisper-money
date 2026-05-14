import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ImportStepUpload } from './import-step-upload';

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
});
