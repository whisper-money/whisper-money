import { BankLogo } from '@/components/bank-logo';
import { StepButton } from '@/components/onboarding/step-button';
import {
    StepBadge,
    StepList,
    StepRow,
} from '@/components/onboarding/step-list';
import {
    StepField,
    stepControlClass,
} from '@/components/onboarding/step-screen';
import { ReplaceConnectionWarning } from '@/components/open-banking/replace-connection-warning';
import { Input } from '@/components/ui/input';
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
import { Check, Search } from 'lucide-react';
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

    const back = (
        <StepButton
            text={__('Back')}
            variant="ghost"
            onClick={() => {
                trigger('light');
                handleBack();
            }}
        />
    );

    return (
        <div className="flex w-full flex-col gap-6">
            {error && (
                <p className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">
                    {error}
                </p>
            )}

            {step === 'country' && (
                <>
                    <StepField label={__('Country')}>
                        <Select value={country} onValueChange={setCountry}>
                            <SelectTrigger className={stepControlClass}>
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
                    </StepField>

                    <div className="flex flex-col gap-2.5">
                        <StepButton
                            text={isLoading ? __('Loading...') : __('Continue')}
                            disabled={!country || isLoading}
                            onClick={() => fetchInstitutions(country)}
                        />
                        {back}
                    </div>
                </>
            )}

            {step === 'bank' && (
                <>
                    <div className="relative">
                        <Search className="pointer-events-none absolute top-1/2 left-4 size-[18px] -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder={__('Search banks...')}
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            autoFocus
                            className={`${stepControlClass} pl-11`}
                        />
                    </div>

                    <div className="max-h-[22rem] overflow-y-auto">
                        <StepList>
                            {filteredInstitutions.map((institution, index) => {
                                const isSelected =
                                    selectedBank?.name === institution.name;

                                return (
                                    <StepRow
                                        key={`${institution.name}-${institution.country}-${index}`}
                                        leading={
                                            <BankLogo
                                                src={institution.logo}
                                                name={institution.name}
                                                fallback="letter"
                                                className="size-7 rounded-md text-xs"
                                            />
                                        }
                                        title={institution.name}
                                        trailing={
                                            isSelected ? (
                                                <Check className="size-5 shrink-0" />
                                            ) : connectedBankNames.has(
                                                  institution.name,
                                              ) ? (
                                                <StepBadge>
                                                    {__('Already connected')}
                                                </StepBadge>
                                            ) : undefined
                                        }
                                        onClick={() =>
                                            setSelectedBank(institution)
                                        }
                                    />
                                );
                            })}
                        </StepList>

                        {filteredInstitutions.length === 0 && (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                {__('No banks found.')}
                            </p>
                        )}
                    </div>

                    <div className="flex flex-col gap-2.5">
                        <StepButton
                            text={__('Continue')}
                            disabled={!selectedBank}
                            onClick={() => setStep('confirm')}
                        />
                        {back}
                    </div>
                </>
            )}

            {step === 'confirm' && selectedBank && (
                <>
                    <div className="flex items-center gap-3.5 rounded-lg border p-4">
                        <BankLogo
                            src={selectedBank.logo}
                            name={selectedBank.name}
                            fallback="letter"
                            className="size-11 shrink-0 rounded-md p-1"
                        />
                        <div className="flex flex-col gap-0.5">
                            <p className="text-base font-medium">
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

                    <div className="flex flex-col gap-2.5">
                        <StepButton
                            text={
                                isSubmitting
                                    ? __('Connecting...')
                                    : __('Connect')
                            }
                            onClick={handleAuthorize}
                            disabled={!canSubmit}
                        />
                        {back}
                    </div>
                </>
            )}
        </div>
    );
}
