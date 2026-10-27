import { CopyableCode } from '@/components/access-tokens/copyable-code';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { __ } from '@/utils/i18n';
import { KeyRound } from 'lucide-react';

/**
 * The plaintext secret of a token that was just created or rotated. The
 * server flashes it once and only stores its hash, so this is the only time
 * the user can copy it.
 */
export function NewTokenAlert({ token }: { token: string }) {
    return (
        <Alert>
            <KeyRound className="h-4 w-4" />
            <AlertTitle>{__('Copy your new token now')}</AlertTitle>
            <AlertDescription className="min-w-0">
                <p className="mb-2">
                    {__(
                        'This is the only time you will see it, so copy it somewhere safe now.',
                    )}
                </p>
                <CopyableCode value={token} className="w-full" />
            </AlertDescription>
        </Alert>
    );
}
