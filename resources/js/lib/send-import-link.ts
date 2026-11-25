import { store } from '@/actions/App/Http/Controllers/OnboardingImportLinkController';
import { __ } from '@/utils/i18n';
import axios from 'axios';
import { toast } from 'sonner';

/**
 * Emails the user a link back to the importer for one of their accounts, for
 * whoever has the bank's export on another computer. Says what went wrong
 * itself and resolves to whether the link went out.
 */
export async function sendImportLink(accountId: string): Promise<boolean> {
    try {
        await axios.post(store().url, { account_id: accountId });

        return true;
    } catch (failure: unknown) {
        toast.error(
            axios.isAxiosError(failure) && failure.response?.status === 429
                ? __(
                      'We already sent you a few links. Check your inbox, or try again in an hour.',
                  )
                : __("We couldn't send the link. Please try again."),
        );

        return false;
    }
}
