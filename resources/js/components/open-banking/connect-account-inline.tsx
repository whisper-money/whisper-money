import { BankLogo } from '@/components/bank-logo';
import { StepButton } from '@/components/onboarding/step-button';
import {
    StepBadge,
    StepCheck,
    StepList,
    StepRow,
} from '@/components/onboarding/step-list';
import {
    StepError,
    StepField,
    StepScreen,
    stepControlClass,
} from '@/components/onboarding/step-screen';
import {
    BetaConnectorBadge,
    BetaConnectorNotice,
} from '@/components/open-banking/beta-connector';
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
import { cn } from '@/lib/utils';
import type { BankingConnection } from '@/types/banking';
import { __ } from '@/utils/i18n';
import { Search } from 'lucide-react';
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

    // Owning the whole screen (not just its middle) is what puts this flow's
    // primary action in the same pinned footer as every other step.
    const { body, action } = {
        country: {
            body: (
                <StepField label={__('Country')}>
                    <Select value={country} onValueChange={setCountry}>
                        <SelectTrigger className={stepControlClass}>
                            <SelectValue placeholder={__('Select country')} />
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
            ),
            action: (
                <StepButton
                    text={isLoading ? __('Loading...') : __('Continue')}
                    disabled={!country || isLoading}
                    onClick={() => fetchInstitutions(country)}
                />
            ),
        },
        bank: {
            body: (
                <>
                    {/* Sticky so refining the search stays possible part-way
                        down a 300-bank country list. */}
                    <div className="sticky top-0 z-10 -mx-1 bg-background px-1 pb-2">
                        <div className="relative">
                            <Search className="pointer-events-none absolute top-1/2 left-4 size-[18px] -translate-y-1/2 text-muted-foreground" />
                            <Input
                                placeholder={__('Search banks...')}
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                autoFocus
                                className={cn(stepControlClass, 'pl-11')}
                            />
                        </div>
                    </div>

                    {filteredInstitutions.length > 0 ? (
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
                                        badge={
                                            institution.beta ? (
                                                <BetaConnectorBadge />
                                            ) : undefined
                                        }
                                        trailing={
                                            isSelected ? (
                                                <StepCheck />
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
                    ) : (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            {__('No banks found.')}
                        </p>
                    )}
                </>
            ),
            action: (
                <StepButton
                    text={__('Continue')}
                    disabled={!selectedBank}
                    onClick={() => setStep('confirm')}
                />
            ),
        },
        confirm: {
            body: selectedBank && (
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

                    {selectedBank.beta && <BetaConnectorNotice />}

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
                </>
            ),
            action: (
                <StepButton
                    text={isSubmitting ? __('Connecting...') : __('Connect')}
                    onClick={handleAuthorize}
                    disabled={!canSubmit}
                />
            ),
        },
    }[step];

    return (
        <StepScreen
            title={__('Connect Your Bank')}
            description={__('Select your country and bank to get started.')}
            footer={
                <>
                    {action}
                    {back}
                </>
            }
        >
            {error && <StepError>{error}</StepError>}
            {body}
        </StepScreen>
    );
}
