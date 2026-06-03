import { store } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { store as storeBank } from '@/actions/App/Http/Controllers/Settings/BankController';
import {
    AccountForm,
    AccountFormData,
} from '@/components/accounts/account-form';
import { BankLogo } from '@/components/bank-logo';
import { StepHeader } from '@/components/onboarding/step-header';
import { ConnectAccountInline } from '@/components/open-banking/connect-account-inline';
import { Button } from '@/components/ui/button';
import { CreatedAccount } from '@/hooks/use-onboarding-state';
import { getCsrfToken } from '@/lib/csrf';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import {
    AlertCircle,
    Check,
    CreditCard,
    Link2,
    PencilLine,
    Plus,
    Zap,
} from 'lucide-react';
import { useCallback, useMemo, useRef, useState } from 'react';
import { StepButton } from './step-button';

type AccountMode = 'select' | 'manual' | 'connected';

interface CreatedAccountDisplay {
    id: string;
    name: string;
    type: string;
    currencyCode: string;
    bankName?: string;
    bankLogo?: string | null;
    connected?: boolean;
}

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
    createdAccounts?: CreatedAccountDisplay[];
    hasSelectedConnectedAccount?: boolean;
    onAccountCreated: (account: CreatedAccount) => void;
    onConnectedAccountSelected?: () => void;
    onContinue?: () => void;
}

export function StepCreateAccount({
    banks,
    isFirstAccount,
    existingAccounts = [],
    createdAccounts = [],
    hasSelectedConnectedAccount = false,
    onAccountCreated,
    onConnectedAccountSelected,
    onContinue,
}: StepCreateAccountProps) {
    const { pricing, subscriptionsEnabled, locale } =
        usePage<SharedData>().props;
    const [mode, setMode] = useState<AccountMode>('select');
    const [selectedMode, setSelectedMode] = useState<'manual' | 'connected'>(
        'connected',
    );
    const [isAddingAnother, setIsAddingAnother] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const formDataRef = useRef<AccountFormData>({
        displayName: '',
        bankId: null,
        type: null,
        currencyCode: null,
        customBank: null,
        balance: null,
        realEstate: null,
        loan: null,
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
                'X-XSRF-TOKEN': getCsrfToken(),
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

    function handleModeContinue() {
        if (selectedMode === 'connected') {
            onConnectedAccountSelected?.();
        }

        setMode(selectedMode);
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
            const isRealEstate = type === 'real_estate';
            let finalBankId: string | null = null;

            if (!isRealEstate) {
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
            }

            const response = await fetch(store.url(), {
                method: 'POST',
                body: JSON.stringify({
                    name: displayName,
                    ...(finalBankId ? { bank_id: finalBankId } : {}),
                    type: type,
                    currency_code: currencyCode,
                    ...(formDataRef.current.balance !== null
                        ? { balance: formDataRef.current.balance }
                        : {}),
                    ...(formDataRef.current.realEstate
                        ? {
                              property_type:
                                  formDataRef.current.realEstate.propertyType,
                              address:
                                  formDataRef.current.realEstate.address ||
                                  null,
                              purchase_price:
                                  formDataRef.current.realEstate
                                      .purchasePrice || null,
                              purchase_date:
                                  formDataRef.current.realEstate.purchaseDate ||
                                  null,
                              area_value:
                                  formDataRef.current.realEstate.areaValue ||
                                  null,
                              area_unit:
                                  formDataRef.current.realEstate.areaUnit,
                              linked_loan_account_id:
                                  formDataRef.current.realEstate
                                      .linkedLoanAccountId,
                              notes:
                                  formDataRef.current.realEstate.notes || null,
                              revaluation_percentage:
                                  formDataRef.current.realEstate
                                      .revaluationPercentage || null,
                          }
                        : {}),
                    ...(formDataRef.current.loan
                        ? {
                              annual_interest_rate:
                                  formDataRef.current.loan.annualInterestRate ||
                                  null,
                              loan_term_months:
                                  formDataRef.current.loan.loanTermMonths ||
                                  null,
                              loan_start_date:
                                  formDataRef.current.loan.startDate || null,
                              original_amount:
                                  formDataRef.current.loan.originalAmount ||
                                  null,
                              linked_real_estate_account_id:
                                  formDataRef.current.loan
                                      .linkedRealEstateAccountId,
                          }
                        : {}),
                }),
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
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

            const matchedBank = banks.find(
                (b) => String(b.id) === String(finalBankId),
            );
            const bankName =
                formDataRef.current.customBank?.name ?? matchedBank?.name;
            const bankLogo = formDataRef.current.customBank
                ? null
                : (matchedBank?.logo ?? null);

            onAccountCreated({
                id: accountData.id || finalBankId,
                name: displayName,
                type: type,
                currencyCode: currencyCode,
                bankName,
                bankLogo,
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
    const hasCreatedAccounts = createdAccounts.length > 0;

    const createdAccountsByBank = useMemo(() => {
        const groups = new Map<string, CreatedAccountDisplay[]>();
        for (const account of createdAccounts) {
            const key = account.bankName ?? 'Bank';
            const group = groups.get(key) ?? [];
            group.push(account);
            groups.set(key, group);
        }
        return Array.from(groups.entries());
    }, [createdAccounts]);

    const existingAccountsByBank = useMemo(() => {
        const groups = new Map<string, ExistingAccount[]>();
        for (const account of existingAccounts) {
            const key = account.bank?.name ?? 'Bank';
            const group = groups.get(key) ?? [];
            group.push(account);
            groups.set(key, group);
        }
        return Array.from(groups.entries());
    }, [existingAccounts]);

    const { title, description } = useMemo(() => {
        if (hasExistingAccounts && !isAddingAnother && !hasCreatedAccounts) {
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
    }, [
        hasExistingAccounts,
        isFirstAccount,
        isAddingAnother,
        hasCreatedAccounts,
    ]);

    // Show created accounts list view (after creating accounts in this session)
    if (hasCreatedAccounts && !isAddingAnother) {
        return (
            <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
                <StepHeader
                    icon={CreditCard}
                    iconContainerClassName="bg-gradient-to-br from-emerald-400 to-teal-500"
                    title={__('Your Accounts')}
                    description={__(
                        'Add more accounts or continue to set up your categories.',
                    )}
                />

                <div className="mb-6 w-full max-w-md space-y-3">
                    {createdAccountsByBank.map(([bankName, accounts]) => (
                        <div
                            key={bankName}
                            className="overflow-hidden rounded-lg border bg-card"
                        >
                            <div className="flex items-center gap-2 border-b px-3 py-2.5">
                                <BankLogo
                                    src={accounts[0].bankLogo}
                                    name={bankName}
                                    fallback="letter"
                                    className="h-5 w-5 text-[10px]"
                                />
                                <span className="text-sm font-medium">
                                    {bankName}
                                </span>
                            </div>
                            <div className="divide-y">
                                {accounts.map((account) => (
                                    <div
                                        key={account.id}
                                        className="flex items-center gap-2 px-3 py-2"
                                    >
                                        <Check className="h-3.5 w-3.5 shrink-0 text-emerald-500" />
                                        <span className="flex-1 text-sm">
                                            {account.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {account.currencyCode}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>

                <div className="w-full max-w-md space-y-3">
                    <Button
                        type="button"
                        variant="outline"
                        className="w-full gap-2"
                        onClick={() => setIsAddingAnother(true)}
                    >
                        <Plus className="h-4 w-4" />
                        {__('Add another account')}
                    </Button>

                    <StepButton
                        text={__('Continue')}
                        className="w-full sm:w-full"
                        onClick={onContinue}
                    />
                </div>
            </div>
        );
    }

    // Show existing accounts view
    if (hasExistingAccounts && !isAddingAnother) {
        return (
            <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
                <StepHeader
                    icon={CreditCard}
                    iconContainerClassName="bg-gradient-to-br from-emerald-400 to-teal-500"
                    title={title}
                    description={description}
                />

                <div className="w-full max-w-md">
                    <div className="mb-6 space-y-3">
                        {existingAccountsByBank.map(([bankName, accounts]) => (
                            <div
                                key={bankName}
                                className="overflow-hidden rounded-lg border bg-card"
                            >
                                <div className="flex items-center gap-2 border-b px-3 py-2.5">
                                    <BankLogo
                                        src={accounts[0].bank?.logo}
                                        name={bankName}
                                        fallback="letter"
                                        className="h-5 w-5 text-[10px]"
                                    />
                                    <span className="text-sm font-medium">
                                        {bankName}
                                    </span>
                                </div>
                                <div className="divide-y">
                                    {accounts.map((account) => (
                                        <div
                                            key={account.id}
                                            className="flex items-center gap-2 px-3 py-2"
                                        >
                                            <Check className="h-3.5 w-3.5 shrink-0 text-emerald-500" />
                                            <span className="flex-1 text-sm">
                                                {account.name || 'Account'}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {account.type
                                                    .split('_')
                                                    .map(
                                                        (w) =>
                                                            w
                                                                .charAt(0)
                                                                .toUpperCase() +
                                                            w.slice(1),
                                                    )
                                                    .join(' ')}{' '}
                                                &middot; {account.currency_code}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>

                    <div className="space-y-2">
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full gap-2"
                            onClick={() => setIsAddingAnother(true)}
                        >
                            <Plus className="h-4 w-4" />
                            {__('Add another account')}
                        </Button>

                        <StepButton
                            text={__('Continue')}
                            className="w-full sm:w-full"
                            onClick={onContinue}
                        />
                    </div>
                </div>
            </div>
        );
    }

    // Connected account inline flow
    if (mode === 'connected') {
        return (
            <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
                <StepHeader
                    icon={Link2}
                    iconContainerClassName="bg-gradient-to-br from-emerald-400 to-teal-500"
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
                    <AccountForm
                        onChange={handleFormChange}
                        usePrimaryCurrenciesOnly={
                            isFirstAccount &&
                            existingAccounts.length === 0 &&
                            createdAccounts.length === 0
                        }
                    />

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
                <div className={cn('grid gap-3', 'grid-cols-2')}>
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
                    <button
                        type="button"
                        onClick={() => setSelectedMode('connected')}
                        className={cn(
                            'relative flex flex-col rounded-xl border p-4 text-left transition-all duration-200',
                            selectedMode === 'connected'
                                ? 'border-emerald-500 bg-emerald-50 ring-2 ring-emerald-500 dark:bg-emerald-950/30'
                                : 'border-border bg-card hover:border-muted-foreground/40',
                        )}
                    >
                        {subscriptionsEnabled && (
                            <span className="absolute top-2.5 right-2.5 rounded-full bg-gradient-to-r from-emerald-500 to-teal-500 px-2 py-0.5 text-[10px] font-semibold tracking-wide text-white uppercase">
                                Standard
                            </span>
                        )}
                        <div className="mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100 dark:bg-emerald-900/40">
                            <Zap className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
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
                            !hasSelectedConnectedAccount &&
                            cheapestMonthlyPrice !== null && (
                                <p className="mt-2 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                    {__('From')}{' '}
                                    {formatCurrency(
                                        cheapestMonthlyPrice * 100,
                                        pricing.currency,
                                        locale,
                                    )}
                                    {__('/month')}
                                </p>
                            )}
                    </button>
                </div>

                {selectedMode === 'connected' &&
                    subscriptionsEnabled &&
                    !hasSelectedConnectedAccount && (
                        <div className="rounded-lg border border-emerald-100 bg-emerald-50 p-3 text-sm dark:border-emerald-900/50 dark:bg-emerald-900/20">
                            <p className="text-center text-sm text-emerald-700 dark:text-emerald-300">
                                {__(
                                    "Connected accounts are a Standard Plan feature. You'll choose a plan at the end of the onboarding.",
                                )}
                            </p>
                        </div>
                    )}

                <div className="w-full max-w-md space-y-2">
                    <StepButton
                        className="w-full sm:w-full"
                        text={__('Continue')}
                        onClick={handleModeContinue}
                    />

                    {(hasCreatedAccounts || hasExistingAccounts) && (
                        <Button
                            type="button"
                            className="w-full opacity-50 transition-all duration-200 hover:opacity-100"
                            variant="ghost"
                            onClick={() => setIsAddingAnother(false)}
                        >
                            {__('Back to accounts')}
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}
