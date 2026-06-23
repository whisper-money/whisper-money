import { BankLogo } from '@/components/bank-logo';
import { IntegrationRequestsDrawer } from '@/components/integration-requests/integration-requests-drawer';
import { ReplaceConnectionWarning } from '@/components/open-banking/replace-connection-warning';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { CONNECT_COUNTRIES, useConnectFlow } from '@/hooks/use-connect-flow';
import { ProviderCredentialFields } from '@/lib/connect-providers';
import type { BankingConnection } from '@/types/banking';
import { __ } from '@/utils/i18n';
import { useEffect, useState } from 'react';

interface ConnectAccountDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    connections?: BankingConnection[];
}

export function ConnectAccountDialog({
    open,
    onOpenChange,
    connections = [],
}: ConnectAccountDialogProps) {
    const {
        step,
        setStep,
        country,
        setCountry,
        filteredInstitutions,
        searchQuery,
        setSearchQuery,
        selectedBank,
        setSelectedBank,
        isLoading,
        isSubmitting,
        error,
        credentials,
        setCredential,
        provider,
        connectedBankNames,
        isAlreadyConnected,
        acknowledgedReplace,
        setAcknowledgedReplace,
        canSubmit,
        fetchInstitutions,
        handleAuthorize,
        reset,
    } = useConnectFlow(connections);

    const [integrationDrawerOpen, setIntegrationDrawerOpen] = useState(false);

    useEffect(() => {
        if (!open) {
            reset();
        }
    }, [open, reset]);

    return (
        <>
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent className="sm:max-w-[500px]">
                    <DialogHeader>
                        <DialogTitle>{__('Connect Bank Account')}</DialogTitle>
                        <DialogDescription>
                            {step === 'country' &&
                                __(
                                    'Select the country where your bank is located.',
                                )}
                            {step === 'bank' && __('Select your bank.')}
                            {step === 'confirm' &&
                                (provider
                                    ? __(provider.headerDescription)
                                    : __(
                                          'You will be redirected to your bank to authorize access.',
                                      ))}
                        </DialogDescription>
                    </DialogHeader>

                    {error && (
                        <p className="text-sm text-destructive">{error}</p>
                    )}

                    {step === 'country' && (
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <Label>{__('Country')}</Label>
                                <Select
                                    value={country}
                                    onValueChange={setCountry}
                                >
                                    <SelectTrigger>
                                        <SelectValue
                                            placeholder={__('Select country')}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CONNECT_COUNTRIES.map((c) => (
                                            <SelectItem
                                                key={c.code}
                                                value={c.code}
                                            >
                                                {__(c.name)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {__('Cancel')}
                                </Button>
                                <Button
                                    disabled={!country || isLoading}
                                    onClick={() => fetchInstitutions(country)}
                                >
                                    {isLoading
                                        ? __('Loading...')
                                        : __('Continue')}
                                </Button>
                            </div>
                        </div>
                    )}

                    {step === 'bank' && (
                        <div className="space-y-4">
                            <Input
                                placeholder={__('Search banks...')}
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                            />

                            <div className="max-h-[300px] space-y-1 overflow-y-auto">
                                {filteredInstitutions.map(
                                    (institution, index) => {
                                        const isConnected =
                                            connectedBankNames.has(
                                                institution.name,
                                            );

                                        return (
                                            <button
                                                key={`${institution.name}-${institution.country}-${index}`}
                                                type="button"
                                                className={`flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm transition-colors hover:bg-accent ${
                                                    selectedBank?.name ===
                                                    institution.name
                                                        ? 'bg-accent'
                                                        : ''
                                                }`}
                                                onClick={() =>
                                                    setSelectedBank(institution)
                                                }
                                            >
                                                <BankLogo
                                                    src={institution.logo}
                                                    className="h-6 w-6"
                                                />
                                                <span>{institution.name}</span>
                                                {isConnected && (
                                                    <Badge
                                                        variant="secondary"
                                                        className="ml-auto"
                                                    >
                                                        {__(
                                                            'Already connected',
                                                        )}
                                                    </Badge>
                                                )}
                                            </button>
                                        );
                                    },
                                )}
                                {filteredInstitutions.length === 0 && (
                                    <p className="py-4 text-center text-sm text-muted-foreground">
                                        {__('No banks found.')}
                                    </p>
                                )}
                            </div>

                            <button
                                type="button"
                                className="cursor-pointer border-b border-dotted text-sm text-primary hover:border-solid"
                                onClick={() => setIntegrationDrawerOpen(true)}
                            >
                                {__(
                                    "Can't find your bank? Request or vote for it",
                                )}
                            </button>

                            <div className="flex justify-end gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => setStep('country')}
                                >
                                    {__('Back')}
                                </Button>
                                <Button
                                    disabled={!selectedBank}
                                    onClick={() => setStep('confirm')}
                                >
                                    {__('Continue')}
                                </Button>
                            </div>
                        </div>
                    )}

                    {step === 'confirm' && selectedBank && (
                        <div className="space-y-4">
                            <div className="rounded-lg border p-4">
                                <div className="flex items-center gap-3">
                                    <BankLogo
                                        src={selectedBank.logo}
                                        className="size-16 p-1"
                                    />
                                    <div>
                                        <p className="font-medium">
                                            {selectedBank.name}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {provider
                                                ? __(provider.cardDescription)
                                                : __(
                                                      'You will be redirected to authorize access to your account data.',
                                                  )}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            {isAlreadyConnected && (
                                <ReplaceConnectionWarning
                                    acknowledged={acknowledgedReplace}
                                    onAcknowledgedChange={
                                        setAcknowledgedReplace
                                    }
                                />
                            )}

                            {provider && (
                                <ProviderCredentialFields
                                    provider={provider}
                                    values={credentials}
                                    onChange={setCredential}
                                />
                            )}

                            <div className="flex justify-end gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => setStep('bank')}
                                    disabled={isSubmitting}
                                >
                                    {__('Back')}
                                </Button>
                                <Button
                                    onClick={handleAuthorize}
                                    disabled={!canSubmit}
                                >
                                    {isSubmitting
                                        ? __('Connecting...')
                                        : __('Connect')}
                                </Button>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            <IntegrationRequestsDrawer
                open={integrationDrawerOpen}
                onOpenChange={setIntegrationDrawerOpen}
            />
        </>
    );
}
