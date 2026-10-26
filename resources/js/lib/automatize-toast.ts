import { captureEvent } from '@/lib/posthog';
import { __ } from '@/utils/i18n';
import { createElement } from 'react';
import { toast } from 'sonner';

/**
 * The "Transaction categorized" toast, with an Automatize action that opens
 * the rule dialog for that transaction and category.
 */
export function showAutomatizeToast({
    source,
    onAutomatize,
    id,
    duration = 12000,
}: {
    /** Where the categorization happened, for analytics. */
    source: string;
    onAutomatize: () => void;
    id?: string;
    duration?: number;
}): void {
    toast.success(__('Transaction categorized'), {
        id,
        closeButton: true,
        duration,
        action: {
            label: createElement(
                'span',
                {
                    title: __(
                        'Automatize the categorization of future transactions like this one',
                    ),
                },
                __('Automatize'),
            ),
            onClick: () => {
                captureEvent('automation_rule_toast_automatize_clicked', {
                    source,
                });
                onAutomatize();
            },
        },
    });
}
