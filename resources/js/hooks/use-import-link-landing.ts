import { captureEvent } from '@/lib/posthog';
import { type SharedData } from '@/types';
import { type UUID } from '@/types/uuid';
import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * The account an emailed "import from your computer" link was opened for, or
 * null. The landing flashes it for one page, so it is read once and kept:
 * a partial reload afterwards must not drop it.
 *
 * Reports the link as opened, once, with where it landed and nothing else.
 */
export function useImportLinkLanding(
    landing: 'onboarding' | 'importer',
): UUID | null {
    const { flash } = usePage<SharedData>().props;
    const [accountId] = useState<UUID | null>(
        () => (flash?.import_link_account_id as UUID | undefined) ?? null,
    );
    const reportedRef = useRef(false);

    useEffect(() => {
        if (accountId && !reportedRef.current) {
            reportedRef.current = true;
            captureEvent('onboarding_import_link_opened', { landing });
        }
    }, [accountId, landing]);

    return accountId;
}
