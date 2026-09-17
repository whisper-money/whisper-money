import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import {
    StepCallout,
    StepError,
    StepScreen,
} from '@/components/onboarding/step-screen';
import { __ } from '@/utils/i18n';
import { Check } from 'lucide-react';

interface StepConnectFailedProps {
    bankName: string;
    /** What the bank or the provider actually said, when it said anything. */
    message?: string | null;
    onRetry: () => void;
    onDifferentBank: () => void;
    onManual: () => void;
}

/**
 * The return from a bank that refused, which until now was a flash message on a
 * hub that looked exactly as it did before the user left.
 *
 * The connection row is already gone by the time this renders
 * (`AuthorizationController::handleAuthorizationError` deletes an unfinished
 * one), which is precisely what the first two rows are for: from the user's side
 * an interrupted bank login looks like something half-done was left behind.
 */
export function StepConnectFailed({
    bankName,
    message,
    onRetry,
    onDifferentBank,
    onManual,
}: StepConnectFailedProps) {
    return (
        <StepScreen
            title={__(':bank didn’t let us in', { bank: bankName })}
            description={__(
                'This usually means the login was cancelled, or the bank’s own session timed out while you were in there.',
            )}
            footer={
                <>
                    <StepButton
                        text={__('Try :bank again', { bank: bankName })}
                        onClick={onRetry}
                    />
                    <StepButton
                        text={__('Use a different bank')}
                        variant="outline"
                        onClick={onDifferentBank}
                    />
                    <StepButton
                        text={__('Bring a file instead')}
                        variant="ghost"
                        onClick={onManual}
                    />
                </>
            }
        >
            {message && <StepError>{message}</StepError>}

            <StepList>
                <StepRow
                    icon={Check}
                    title={__('Nothing was created')}
                    description={__('No half-connected account left behind.')}
                />
                <StepRow
                    icon={Check}
                    title={__('Nothing was charged')}
                    description={__('You are exactly where you were.')}
                />
            </StepList>

            <StepCallout>
                {__(
                    'If it happens twice, it is normally the bank and not you. Try a different bank or bring a file instead — you can add :bank later without redoing any of this.',
                    { bank: bankName },
                )}
            </StepCallout>
        </StepScreen>
    );
}
