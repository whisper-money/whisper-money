import { BankLogo } from '@/components/bank-logo';
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
import type { EnableBankingInstitution } from '@/types/banking';
import { __ } from '@/utils/i18n';
import { useCallback, useEffect, useState } from 'react';

const COUNTRIES = [
    { code: 'ES', name: 'Spain' },
    { code: 'DE', name: 'Germany' },
    { code: 'FR', name: 'France' },
    { code: 'IT', name: 'Italy' },
    { code: 'NL', name: 'Netherlands' },
    { code: 'PT', name: 'Portugal' },
    { code: 'BE', name: 'Belgium' },
    { code: 'AT', name: 'Austria' },
    { code: 'FI', name: 'Finland' },
    { code: 'IE', name: 'Ireland' },
    { code: 'LT', name: 'Lithuania' },
    { code: 'LV', name: 'Latvia' },
    { code: 'EE', name: 'Estonia' },
    { code: 'SE', name: 'Sweden' },
    { code: 'NO', name: 'Norway' },
    { code: 'DK', name: 'Denmark' },
    { code: 'PL', name: 'Poland' },
    { code: 'GB', name: 'United Kingdom' },
] as const;

interface ConnectAccountDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

type Step = 'country' | 'bank' | 'confirm';

export function ConnectAccountDialog({
    open,
    onOpenChange,
}: ConnectAccountDialogProps) {
    const [step, setStep] = useState<Step>('country');
    const [country, setCountry] = useState<string>('');
    const [institutions, setInstitutions] = useState<
        EnableBankingInstitution[]
    >([]);
    const [filteredInstitutions, setFilteredInstitutions] = useState<
        EnableBankingInstitution[]
    >([]);
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedBank, setSelectedBank] =
        useState<EnableBankingInstitution | null>(null);
    const [isLoading, setIsLoading] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const resetState = useCallback(() => {
        setStep('country');
        setCountry('');
        setInstitutions([]);
        setFilteredInstitutions([]);
        setSearchQuery('');
        setSelectedBank(null);
        setIsLoading(false);
        setIsSubmitting(false);
        setError(null);
    }, []);

    useEffect(() => {
        if (!open) {
            resetState();
        }
    }, [open, resetState]);

    useEffect(() => {
        if (searchQuery) {
            setFilteredInstitutions(
                institutions.filter((i) =>
                    i.name.toLowerCase().includes(searchQuery.toLowerCase()),
                ),
            );
        } else {
            setFilteredInstitutions(institutions);
        }
    }, [searchQuery, institutions]);

    async function fetchInstitutions(countryCode: string) {
        setIsLoading(true);
        setError(null);

        try {
            const response = await fetch(
                `/open-banking/institutions?country=${countryCode}`,
                {
                    headers: {
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': decodeURIComponent(
                            document.cookie
                                .split('; ')
                                .find((row) => row.startsWith('XSRF-TOKEN='))
                                ?.split('=')[1] || '',
                        ),
                    },
                },
            );

            if (!response.ok) {
                throw new Error('Failed to fetch banks');
            }

            const data = await response.json();
            setInstitutions(data);
            setFilteredInstitutions(data);
            setStep('bank');
        } catch {
            setError(__('Failed to load banks. Please try again.'));
        } finally {
            setIsLoading(false);
        }
    }

    async function handleAuthorize() {
        if (!selectedBank) return;

        setIsSubmitting(true);
        setError(null);

        try {
            const response = await fetch('/open-banking/authorize', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie
                            .split('; ')
                            .find((row) => row.startsWith('XSRF-TOKEN='))
                            ?.split('=')[1] || '',
                    ),
                },
                body: JSON.stringify({
                    aspsp_name: selectedBank.name,
                    country: country,
                    logo: selectedBank.logo,
                }),
            });

            if (!response.ok) {
                throw new Error('Failed to start authorization');
            }

            const data = await response.json();
            window.location.href = data.redirect_url;
        } catch {
            setError(__('Failed to connect to your bank. Please try again.'));
            setIsSubmitting(false);
        }
    }

    return (
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
                            __(
                                'You will be redirected to your bank to authorize access.',
                            )}
                    </DialogDescription>
                </DialogHeader>

                {error && <p className="text-sm text-destructive">{error}</p>}

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
                                    {COUNTRIES.map((c) => (
                                        <SelectItem key={c.code} value={c.code}>
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
                                {isLoading ? __('Loading...') : __('Continue')}
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
                                </button>
                            ))}
                            {filteredInstitutions.length === 0 && (
                                <p className="py-4 text-center text-sm text-muted-foreground">
                                    {__('No banks found.')}
                                </p>
                            )}
                        </div>

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
                                        {__(
                                            'You will be redirected to authorize access to your account data.',
                                        )}
                                    </p>
                                </div>
                            </div>
                        </div>

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
                                disabled={isSubmitting}
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
    );
}
