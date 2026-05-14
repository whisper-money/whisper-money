import { __ } from '@/utils/i18n';

export function getBulkDeleteConfirmationText(count: number): string {
    return __('Delete :count Transactions', { count });
}
