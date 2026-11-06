import { store as sendImportLink } from '@/actions/App/Http/Controllers/OnboardingImportLinkController';
import { StepButton } from '@/components/onboarding/step-button';
import { StepChevron, StepRow } from '@/components/onboarding/step-list';
import { StepCallout, StepScreen } from '@/components/onboarding/step-screen';
import { Spinner } from '@/components/ui/spinner';
import { captureEvent } from '@/lib/posthog';
import { type UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';
import axios from 'axios';
import { Laptop } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

interface ImportLinkRowProps {
    accountId: UUID;
    onSent: () => void;
}

/**
 * "Send me a link to import from my computer": on a phone, getting a CSV out
 * of the bank is the hard part, so the step offers to pick it up on a computer
 * instead of ending there.
 */
export function ImportLinkRow({ accountId, onSent }: ImportLinkRowProps) {
    const [isSending, setIsSending] = useState(false);

    const handleSend = () => {
        setIsSending(true);

        axios
            .post(sendImportLink().url, { account_id: accountId })
            .then(() => {
                captureEvent('onboarding_import_link_sent');
                onSent();
            })
            .catch((failure: unknown) => {
                toast.error(
                    axios.isAxiosError(failure) &&
                        failure.response?.status === 429
                        ? __(
                              'We already sent you a few links. Check your inbox, or try again in an hour.',
                          )
                        : __("We couldn't send the link. Please try again."),
                );
                setIsSending(false);
            });
    };

    return (
        <StepRow
            icon={Laptop}
            title={__('On your phone?')}
            description={__(
                'Email me a link to import from my computer instead',
            )}
            trailing={
                isSending ? (
                    <Spinner className="size-4 text-muted-foreground" />
                ) : (
                    <StepChevron />
                )
            }
            onClick={isSending ? undefined : handleSend}
        />
    );
}

interface StepImportLinkSentProps {
    email: string;
    onContinue: () => void;
    onQuickExpenses: () => void;
}

/** The link is on its way; nothing here waits for it to be opened. */
export function StepImportLinkSent({
    email,
    onContinue,
    onQuickExpenses,
}: StepImportLinkSentProps) {
    return (
        <StepScreen
            title={__('Link sent')}
            description={__(
                'Open the email we sent to :email on your computer to import the file there.',
                { email },
            )}
            footer={
                <>
                    <StepButton
                        text={__('Continue for now')}
                        onClick={onContinue}
                    />
                    <StepButton
                        text={__('Type my main expenses instead')}
                        variant="ghost"
                        onClick={onQuickExpenses}
                    />
                </>
            }
        >
            <StepCallout>
                {__(
                    'You can keep going here in the meantime. The link takes you straight back to this account.',
                )}
            </StepCallout>
        </StepScreen>
    );
}
