import { store } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { store as storeBank } from '@/actions/App/Http/Controllers/Settings/BankController';
import {
    AccountForm,
    AccountFormData,
} from '@/components/accounts/account-form';
import { StepHeader } from '@/components/onboarding/step-header';
import { ConnectAccountInline } from '@/components/open-banking/connect-account-inline';
import { Button } from '@/components/ui/button';
import { CreatedAccount } from '@/hooks/use-onboarding-state';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    CreditCard,
    Link2,
    PencilLine,
    Zap,
} from 'lucide-react';
import { useCallback, useMemo, useRef, useState } from 'react';
import { StepButton } from './step-button';

type AccountMode = 'select' | 'manual' | 'connected';

interface ExistingAccount {
    id: string;
    name: string;
    name_iv: string | null;
    encrypted: boolean;
    type: string;
    currency_code: string;
    bank_id: string;
    banking_connection_id: string | null;
    bank?: {
        id: string;
        name: string;
        logo: string | null;
    };
}

interface StepCreateAccountProps {
    banks: { id: string; name: string; logo: string | null }[];
    isFirstAccount: boolean;
    existingAccounts?: ExistingAccount[];
    onAccountCreated: (account: CreatedAccount) => void;
    onSkip?: () => void;
}

export function StepCreateAccount({
    banks,
    isFirstAccount,
    existingAccounts = [],
    onAccountCreated,
    onSkip,
}: StepCreateAccountProps) {
    const { pricing, subscriptionsEnabled, features } =
        usePage<SharedData>().props;
    const openBankingEnabled = features['open-banking'];
    const [mode, setMode] = useState<AccountMode>('select');
    const [selectedMode, setSelectedMode] = useState<'manual' | 'connected'>(
        'manual',
    );
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const formDataRef = useRef<AccountFormData>({
        displayName: '',
        bankId: null,
        type: null,
        currencyCode: null,
        customBank: null,
    });

    // Compute cheapest monthly equivalent across all plans
    const cheapestMonthlyPrice = useMemo(() => {
        const plans = Object.values(pricing.plans);
        if (plans.length === 0) {
            return null;
        }
        return Math.min(
            ...plans.map((plan) =>
                plan.billing_period === 'year' ? plan.price / 12 : plan.price,
            ),
        );
    }, [pricing.plans]);

    const handleFormChange = useCallback((data: AccountFormData) => {
        formDataRef.current = data;
    }, []);

    async function createBankAndGetId(): Promise<string | null> {
        const customBank = formDataRef.current.customBank;
        if (!customBank) {
            return null;
        }

        const formData = new FormData();
        formData.append('name', customBank.name);
        if (customBank.logo) {
            formData.append('logo', customBank.logo);
        }

        const response = await fetch(storeBank.url(), {
            method: 'POST',
            body: formData,
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie
                        .split('; ')
                        .find((row) => row.startsWith('XSRF-TOKEN='))
                        ?.split('=')[1] || '',
                ),
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message || 'Failed to create bank');
        }

        const data = await response.json();
        return data.id;
    }

    async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError(null);

        const { displayName, bankId, type, currencyCode, customBank } =
            formDataRef.current;

        if (!displayName.trim()) {
            setError(__('Please enter an account name.'));
            return;
        }

        if (!type || !currencyCode) {
            setError(__('Please fill in all required fields.'));
            return;
        }

        setIsSubmitting(true);

        try {
            let finalBankId: string;

            if (customBank) {
                if (!customBank.name.trim()) {
                    setError(__('Please enter a bank name.'));
                    setIsSubmitting(false);
                    return;
                }
                const createdBankId = await createBankAndGetId();
                if (!createdBankId) {
                    throw new Error('Failed to create bank');
                }
                finalBankId = createdBankId;
            } else {
                if (!bankId) {
                    setError(__('Please select a bank.'));
                    setIsSubmitting(false);
                    return;
                }
                finalBankId = String(bankId);
            }

            const response = await fetch(store.url(), {
                method: 'POST',
                body: JSON.stringify({
                    name: displayName,
                    bank_id: finalBankId,
                    type: type,
                    currency_code: currencyCode,
                }),
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie
                            .split('; ')
                            .find((row) => row.startsWith('XSRF-TOKEN='))
                            ?.split('=')[1] || '',
                    ),
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(
                    errorData.message ||
                        Object.values(errorData.errors || {})[0] ||
                        'Failed to create account',
                );
            }

            const accountData = await response.json();

            const bankName =
                formDataRef.current.customBank?.name ??
                banks.find((b) => String(b.id) === String(finalBankId))?.name;

            onAccountCreated({
                id: accountData.id || finalBankId,
                name: displayName,
                type: type,
                currencyCode: currencyCode,
                bankName,
            });
            setIsSubmitting(false);
        } catch (err) {
            console.error('Account creation failed:', err);
            setError(
                err instanceof Error
                    ? err.message
                    : __('Failed to create account. Please try again.'),
            );
            setIsSubmitting(false);
        }
    }

    const hasExistingAccounts = existingAccounts.length > 0;

    const { title, description } = useMemo(() => {
        if (hasExistingAccounts) {
            return {
                title: __('Your Accounts'),
                description: __(
                    "You already have accounts set up. Let's continue with the onboarding.",
                ),
            };
        }
        if (isFirstAccount) {
            return {
                title: __('Create an Account'),
                description: __(
                    "Let's set up your first account to start tracking your finances.",
                ),
            };
        }
        return {
            title: __('Add Another Account'),
            description: __(
                'Add another account to track more of your finances.',
            ),
        };
    }, [hasExistingAccounts, isFirstAccount]);

    // Show existing accounts view
    if (hasExistingAccounts) {
        return (
            <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
                <StepHeader
                    icon={CreditCard}
                    iconContainerClassName="bg-gradient-to-br from-emerald-400 to-teal-500"
                    title={title}
                    description={description}
                />

                <div className="w-full max-w-md">
                    <div className="mb-6 space-y-2">
                        {existingAccounts.map((account) => (
                            <div
                                key={account.id}
                                className="flex items-center gap-3 rounded-lg border bg-card p-4"
                            >
                                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                                    <CheckCircle2 className="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                                </div>
                                <div className="flex-1">
                                    <p className="font-medium">
                                        {account.name || 'Account'}
                                    </p>
                                    <p className="flex gap-2 text-sm text-muted-foreground">
                                        <span>
                                            {account.bank?.name ?? `Bank`}
                                        </span>
                                        <span className="opacity-50">
                                            &ndash;
                                        </span>
                                        <span>
                                            {account.type
                                                .split('_')
                                                .map(
                                                    (w) =>
                                                        w
                                                            .charAt(0)
                                                            .toUpperCase() +
                                                        w.slice(1),
                                                )
                                                .join(' ')}
                                        </span>
                                        <span className="opacity-50">
                                            &ndash;
                                        </span>
                                        <span>{account.currency_code}</span>
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>

                    <StepButton
                        text={__('Continue')}
                        className="w-full sm:w-full"
                        onClick={() =>
                            onAccountCreated({
                                id: existingAccounts[0].id,
                                name:
                                    existingAccounts[0].name ||
                                    existingAccounts[0].bank?.name ||
                                    'Account',
                                type: existingAccounts[0].type,
                                currencyCode: existingAccounts[0].currency_code,
                                bankName: existingAccounts[0].bank?.name,
                                connected:
                                    !!existingAccounts[0].banking_connection_id,
                            })
                        }
                    />
                </div>
            </div>
        );
    }

    // Connected account inline flow
    if (openBankingEnabled && mode === 'connected') {
        return (
            <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
                <StepHeader
                    icon={Link2}
                    iconContainerClassName="bg-gradient-to-br from-blue-400 to-indigo-500"
                    title={__('Connect Your Bank')}
                    description={__(
                        'Select your country and bank to get started.',
                    )}
                />
                <ConnectAccountInline onBack={() => setMode('select')} />
            </div>
        );
    }

    // Manual account form
    if (mode === 'manual') {
        return (
            <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
                <StepHeader
                    icon={CreditCard}
                    iconContainerClassName="bg-gradient-to-br from-emerald-400 to-teal-500"
                    title={title}
                    description={description}
                />

                <form
                    onSubmit={handleSubmit}
                    autoFocus
                    className="w-full max-w-md space-y-4"
                >
                    <AccountForm onChange={handleFormChange} />

                    {error && (
                        <div className="flex items-center gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
                            <AlertCircle className="h-4 w-4 shrink-0" />
                            {error}
                        </div>
                    )}

                    <StepButton
                        type="submit"
                        className="w-full sm:w-full"
                        disabled={isSubmitting}
                        loading={isSubmitting}
                        loadingText={__('Creating...')}
                        text={__('Create Account')}
                    />

                    <Button
                        type="button"
                        size="lg"
                        className="w-full opacity-50 transition-all duration-200 hover:opacity-100"
                        variant="ghost"
                        onClick={() => setMode('select')}
                    >
                        {__('Back')}
                    </Button>

                    {!isFirstAccount && onSkip && (
                        <Button
                            type="button"
                            size="lg"
                            className="w-full opacity-50 transition-all duration-200 hover:opacity-100"
                            variant="ghost"
                            disabled={isSubmitting}
                            onClick={() => onSkip()}
                        >
                            {__('Ignore')}
                        </Button>
                    )}
                </form>
            </div>
        );
    }

    // Account type selection screen (default)
    return (
        <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
            <StepHeader
                icon={CreditCard}
                iconContainerClassName="bg-gradient-to-br from-emerald-400 to-teal-500"
                title={title}
                description={__('How would you like to set up this account?')}
            />

            <div className="w-full max-w-md space-y-4">
                <div
                    className={cn(
                        'grid gap-3',
                        openBankingEnabled ? 'grid-cols-2' : 'grid-cols-1',
                    )}
                >
                    {/* Manual Account Card */}
                    <button
                        type="button"
                        onClick={() => setSelectedMode('manual')}
                        className={cn(
                            'flex flex-col rounded-xl border p-4 text-left transition-all duration-200',
                            selectedMode === 'manual'
                                ? 'border-emerald-500 bg-emerald-50 ring-2 ring-emerald-500 dark:bg-emerald-950/30'
                                : 'border-border bg-card hover:border-muted-foreground/40',
                        )}
                    >
                        <div className="mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100 dark:bg-emerald-900/40">
                            <PencilLine className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                        </div>
                        <p className="text-sm font-semibold">{__('Manual')}</p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {__(
                                'Enter transactions yourself or import a CSV file',
                            )}
                        </p>
                    </button>

                    {/* Connected Account Card */}
                    {openBankingEnabled && (
                        <button
                            type="button"
                            onClick={() => setSelectedMode('connected')}
                            className={cn(
                                'relative flex flex-col rounded-xl border p-4 text-left transition-all duration-200',
                                selectedMode === 'connected'
                                    ? 'border-blue-500 bg-blue-50 ring-2 ring-blue-500 dark:bg-blue-950/30'
                                    : 'border-border bg-card hover:border-muted-foreground/40',
                            )}
                        >
                            {subscriptionsEnabled && (
                                <span className="absolute top-2.5 right-2.5 rounded-full bg-gradient-to-r from-blue-500 to-indigo-500 px-2 py-0.5 text-[10px] font-semibold tracking-wide text-white uppercase">
                                    Pro
                                </span>
                            )}
                            <div className="mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/40">
                                <Zap className="h-4 w-4 text-blue-600 dark:text-blue-400" />
                            </div>
                            <p className="text-sm font-semibold">
                                {__('Connected')}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {__(
                                    'Auto-sync transactions directly from your bank',
                                )}
                            </p>
                            {subscriptionsEnabled &&
                                cheapestMonthlyPrice !== null && (
                                    <p className="mt-2 text-xs font-medium text-blue-600 dark:text-blue-400">
                                        {__('From')} $
                                        {cheapestMonthlyPrice.toFixed(0)}
                                        {__('/month')}
                                    </p>
                                )}
                        </button>
                    )}
                </div>

                {openBankingEnabled &&
                    selectedMode === 'connected' &&
                    subscriptionsEnabled && (
                        <div className="rounded-lg border border-blue-100 bg-blue-50 p-3 text-sm dark:border-blue-900/50 dark:bg-blue-900/20">
                            <p className="text-center text-sm text-blue-700 dark:text-blue-300">
                                {__(
                                    "Connected accounts are a Pro feature. You'll choose a plan at the end of the onboarding.",
                                )}
                            </p>
                        </div>
                    )}

                <StepButton
                    className="w-full sm:w-full"
                    text={__('Continue')}
                    onClick={() => setMode(selectedMode)}
                />

                {!isFirstAccount && onSkip && (
                    <Button
                        type="button"
                        size="lg"
                        className="w-full opacity-50 transition-all duration-200 hover:opacity-100"
                        variant="ghost"
                        onClick={() => onSkip()}
                    >
                        {__('Ignore')}
                    </Button>
                )}
            </div>
        </div>
    );
}
