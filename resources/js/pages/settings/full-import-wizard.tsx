import { index as importIndex } from '@/actions/App/Http/Controllers/Settings/FullImportController';
import { FullImportWizard } from '@/components/full-import/full-import-wizard';
import { __ } from '@/utils/i18n';
import { Head, router } from '@inertiajs/react';

/**
 * The full import wizard on a page of its own, without the app's chrome: it
 * is a flow with a beginning and an end, closed back to Settings.
 */
export default function FullImportWizardPage() {
    return (
        <>
            <Head title={__('Import from another app')} />
            <FullImportWizard
                variant="page"
                onClose={() => router.visit(importIndex().url)}
            />
        </>
    );
}
