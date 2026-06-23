import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    connectProviderByKey,
    credentialPayload,
    isProviderComplete,
    ProviderCredentialFields,
} from '@/lib/connect-providers';
import type { BankingConnection } from '@/types/banking';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';

interface UpdateCredentialsDialogProps {
    connection: BankingConnection;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function UpdateCredentialsDialog({
    connection,
    open,
    onOpenChange,
}: UpdateCredentialsDialogProps) {
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [credentials, setCredentials] = useState<Record<string, string>>({});
    const [error, setError] = useState<string | null>(null);

    const provider = connectProviderByKey(connection.provider);

    const setCredential = useCallback((key: string, value: string) => {
        setCredentials((current) => ({ ...current, [key]: value }));
    }, []);

    function resetState() {
        setCredentials({});
        setError(null);
    }

    function handleOpenChange(value: boolean) {
        if (!value) {
            resetState();
        }
        onOpenChange(value);
    }

    function handleSubmit() {
        if (!provider) {
            return;
        }

        setIsSubmitting(true);
        setError(null);

        router.patch(
            `/settings/connections/${connection.id}/credentials`,
            credentialPayload(provider, credentials),
            {
                onSuccess: () => {
                    onOpenChange(false);
                    resetState();
                },
                onError: (errors) => {
                    const fieldError = provider.fields
                        .map((f) => errors[f.key])
                        .find(Boolean);

                    setError(
                        errors.credentials ??
                            fieldError ??
                            __(
                                'Failed to update credentials. Please try again.',
                            ),
                    );
                },
                onFinish: () => {
                    setIsSubmitting(false);
                },
            },
        );
    }

    const isValid = provider
        ? isProviderComplete(provider, credentials)
        : false;

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>{__('Update Credentials')}</DialogTitle>
                    <DialogDescription>
                        {__('Enter your new API credentials for :provider.', {
                            provider: connection.aspsp_name,
                        })}
                    </DialogDescription>
                </DialogHeader>

                {error && <p className="text-sm text-destructive">{error}</p>}

                {provider && (
                    <ProviderCredentialFields
                        provider={provider}
                        values={credentials}
                        onChange={setCredential}
                        idPrefix="update"
                    />
                )}

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => handleOpenChange(false)}
                        disabled={isSubmitting}
                    >
                        {__('Cancel')}
                    </Button>
                    <Button
                        onClick={handleSubmit}
                        disabled={isSubmitting || !isValid}
                    >
                        {isSubmitting
                            ? __('Updating...')
                            : __('Update Credentials')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
