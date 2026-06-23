import { BankLogo } from '@/components/bank-logo';
import { ReplaceConnectionWarning } from '@/components/open-banking/replace-connection-warning';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { useWebHaptics } from '@/hooks/use-web-haptics';
import { ProviderCredentialFields } from '@/lib/connect-providers';
import type { BankingConnection } from '@/types/banking';
import { __ } from '@/utils/i18n';
import { ArrowLeft } from 'lucide-react';
import { useCallback } from 'react';

interface ConnectAccountInlineProps {
    onBack: () => void;
    connections?: BankingConnection[];
}

export function ConnectAccountInline({
    onBack,
    connections = [],
}: ConnectAccountInlineProps) {
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
        clearBankSelection,
    } = useConnectFlow(connections);

    const { trigger } = useWebHaptics();

    const handleBack = useCallback(() => {
        if (step === 'country') {
            onBack();
        } else if (step === 'bank') {
            setStep('country');
            clearBankSelection();
        } else if (step === 'confirm') {
            setStep('bank');
        }
    }, [step, onBack, setStep, clearBankSelection]);

    return (
        <div className="w-full max-w-md space-y-4">
            {error && (
                <p className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">
                    {error}
                </p>
            )}

            {step === 'country' && (
                <div className="space-y-4">
                    <div className="space-y-2">
                        <Label>{__('Country')}</Label>
                        <Select value={country} onValueChange={setCountry}>
                            <SelectTrigger>
                                <SelectValue
                                    placeholder={__('Select country')}
                                />
                            </SelectTrigger>
                            <SelectContent>
                                {CONNECT_COUNTRIES.map((c) => (
                                    <SelectItem key={c.code} value={c.code}>
                                        {__(c.name)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-2">
                        <Button
                            className="w-full"
                            size="lg"
                            disabled={!country || isLoading}
                            onClick={() => fetchInstitutions(country)}
                        >
                            {isLoading ? __('Loading...') : __('Continue')}
                        </Button>

                        <Button
                            variant={'ghost'}
                            type="button"
                            onClick={() => {
                                trigger('light');
                                handleBack();
                            }}
                            className="w-full"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            {__('Back')}
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
                        autoFocus
                    />

                    <div className="max-h-[300px] space-y-1 overflow-y-auto rounded-lg border p-1">
                        {filteredInstitutions.map((institution) => (
                            <button
                                key={institution.name}
                                type="button"
                                className={`flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm transition-colors hover:bg-accent ${
                                    selectedBank?.name === institution.name
                                        ? 'bg-accent'
                                        : ''
                                }`}
                                onClick={() => setSelectedBank(institution)}
                            >
                                <BankLogo
                                    src={institution.logo}
                                    className="h-6 w-6"
                                />
                                <span>{institution.name}</span>
                                {connectedBankNames.has(institution.name) && (
                                    <Badge
                                        variant="secondary"
                                        className="ml-auto"
                                    >
                                        {__('Already connected')}
                                    </Badge>
                                )}
                            </button>
                        ))}
                        {filteredInstitutions.length === 0 && (
                            <p className="py-4 text-center text-sm text-muted-foreground">
                                {__('No banks found.')}
                            </p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Button
                            className="w-full"
                            size="lg"
                            disabled={!selectedBank}
                            onClick={() => setStep('confirm')}
                        >
                            {__('Continue')}
                        </Button>
                        <Button
                            variant={'ghost'}
                            type="button"
                            onClick={() => {
                                trigger('light');
                                handleBack();
                            }}
                            className="w-full"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            {__('Back')}
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
                                className="size-12 p-1"
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
                            onAcknowledgedChange={setAcknowledgedReplace}
                        />
                    )}

                    {provider && (
                        <ProviderCredentialFields
                            provider={provider}
                            values={credentials}
                            onChange={setCredential}
                            idPrefix="inline"
                        />
                    )}

                    <Button
                        className="w-full"
                        size="lg"
                        onClick={handleAuthorize}
                        disabled={!canSubmit}
                    >
                        {isSubmitting ? __('Connecting...') : __('Connect')}
                    </Button>
                </div>
            )}
        </div>
    );
}
