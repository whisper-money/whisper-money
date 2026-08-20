import { store } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { store as storeBank } from '@/actions/App/Http/Controllers/Settings/BankController';
import {
    AccountForm,
    AccountFormData,
} from '@/components/accounts/account-form';
import { BankLogo } from '@/components/bank-logo';
import { StepButton } from '@/components/onboarding/step-button';
import {
    StepBadge,
    StepList,
    StepRow,
} from '@/components/onboarding/step-list';
import { StepScreen } from '@/components/onboarding/step-screen';
import { ConnectAccountInline } from '@/components/open-banking/connect-account-inline';
import { useCheapestMonthlyPrice } from '@/hooks/use-cheapest-monthly-price';
import { CreatedAccount } from '@/hooks/use-onboarding-state';
import { getCsrfToken } from '@/lib/csrf';
import { type SharedData } from '@/types';
import { formatAccountType, type AccountType } from '@/types/account';
import { type SignupPlan } from '@/types/pricing';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { AlertCircle, Check, PencilLine, Plus, Zap } from 'lucide-react';
import { useCallback, useMemo, useRef, useState } from 'react';

type AccountMode = 'select' | 'manual' | 'connected';

/** One account row, however it reached the screen. */
interface AccountLine {
    id: string;
    name: string;
    bankName: string;
    bankLogo: string | null;
    meta: string;
}

interface AccountGroup {
    bankName: string;
    bankLogo: string | null;
    accounts: AccountLine[];
}

interface CreatedAccountDisplay {
    id: string;
    name: string;
    type: string;
    currencyCode: string;
    bankName?: string;
    bankLogo?: string | null;
    connected?: boolean;
}

export interface ExistingAccount {
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
    signupPlan?: SignupPlan | null;
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
    signupPlan = null,
    onAccountCreated,
    onConnectedAccountSelected,
    onContinue,
}: StepCreateAccountProps) {
    const { pricing, subscriptionsEnabled, locale } =
        usePage<SharedData>().props;
    const cheapestMonthlyPrice = useCheapestMonthlyPrice();
    // Someone who signed up from the free card gets no bank connections, so the
    // chooser has a single option left: drop it and open the manual form
    // straight away.
    const isFreePlan = signupPlan === 'free';
    const [mode, setMode] = useState<AccountMode>(
        isFreePlan ? 'manual' : 'select',
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

    function selectMode(next: 'manual' | 'connected') {
        if (next === 'connected') {
            onConnectedAccountSelected?.();
        }

        setMode(next);
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

    // Shown until the user has committed to a connected account once; after
    // that repeating the price on every extra account is just noise.
    const connectedPlanNotice = useMemo(() => {
        if (
            !subscriptionsEnabled ||
            signupPlan === 'paid' ||
            hasSelectedConnectedAccount ||
            cheapestMonthlyPrice === null
        ) {
            return undefined;
        }

        return __(
            'Standard plan, from :price/month. You will choose a plan at the end of the onboarding.',
            {
                price: formatCurrency(
                    cheapestMonthlyPrice * 100,
                    pricing.currency,
                    locale,
                ),
            },
        );
    }, [
        subscriptionsEnabled,
        signupPlan,
        hasSelectedConnectedAccount,
        cheapestMonthlyPrice,
        pricing.currency,
        locale,
    ]);

    const hasExistingAccounts = existingAccounts.length > 0;
    const hasCreatedAccounts = createdAccounts.length > 0;

    // Created and existing accounts render as the same grouped list, so both
    // shapes are normalised once instead of being laid out twice.
    const accountGroups = useMemo((): AccountGroup[] => {
        const source: AccountLine[] = hasCreatedAccounts
            ? createdAccounts.map((account) => ({
                  id: account.id,
                  name: account.name,
                  bankName: account.bankName ?? __('Bank'),
                  bankLogo: account.bankLogo ?? null,
                  meta: `${formatAccountType(account.type as AccountType)} \u00b7 ${account.currencyCode}`,
              }))
            : existingAccounts.map((account) => ({
                  id: account.id,
                  name: account.name || __('Account'),
                  bankName: account.bank?.name ?? __('Bank'),
                  bankLogo: account.bank?.logo ?? null,
                  meta: `${formatAccountType(account.type as AccountType)} \u00b7 ${account.currency_code}`,
              }));

        const groups = new Map<string, AccountGroup>();

        for (const line of source) {
            const group = groups.get(line.bankName) ?? {
                bankName: line.bankName,
                bankLogo: line.bankLogo,
                accounts: [],
            };
            group.accounts.push(line);
            groups.set(line.bankName, group);
        }

        return Array.from(groups.values());
    }, [hasCreatedAccounts, createdAccounts, existingAccounts]);

    const { title, description } = useMemo(() => {
        if (hasCreatedAccounts && !isAddingAnother) {
            return {
                title: __('Your Accounts'),
                description: __(
                    'Add more accounts or continue to set up your categories.',
                ),
            };
        }
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

    // Accounts already set up: one grouped list for both created and existing.
    if (accountGroups.length > 0 && !isAddingAnother) {
        return (
            <StepScreen
                title={title}
                description={description}
                footer={
                    <>
                        <StepButton
                            text={__('Add another account')}
                            variant="outline"
                            icon={Plus}
                            onClick={() => setIsAddingAnother(true)}
                        />
                        <StepButton
                            text={__('Continue')}
                            onClick={onContinue}
                        />
                    </>
                }
            >
                <div className="flex flex-col gap-1">
                    {accountGroups.map((group) => (
                        <div key={group.bankName}>
                            <div className="flex items-center gap-2.5 pt-4 pb-2.5 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                <BankLogo
                                    src={group.bankLogo}
                                    name={group.bankName}
                                    fallback="letter"
                                    className="size-7 rounded-md text-xs"
                                />
                                {group.bankName}
                            </div>
                            <StepList>
                                {group.accounts.map((account) => (
                                    <StepRow
                                        key={account.id}
                                        title={account.name}
                                        description={account.meta}
                                        trailing={
                                            <Check className="size-5 shrink-0 text-muted-foreground" />
                                        }
                                    />
                                ))}
                            </StepList>
                        </div>
                    ))}
                </div>
            </StepScreen>
        );
    }

    // Connected account inline flow
    if (mode === 'connected') {
        return (
            <StepScreen
                title={__('Connect Your Bank')}
                description={__('Select your country and bank to get started.')}
            >
                <ConnectAccountInline onBack={() => setMode('select')} />
            </StepScreen>
        );
    }

    // Manual account form
    if (mode === 'manual') {
        return (
            <StepScreen
                title={title}
                description={description}
                footer={
                    <>
                        <StepButton
                            type="submit"
                            form="onboarding-account"
                            disabled={isSubmitting}
                            loading={isSubmitting}
                            loadingText={__('Creating...')}
                            text={__('Create Account')}
                        />
                        {/* A free signup skips the mode chooser, so its only
                            way back is the account list — which exists exactly
                            when one of the early returns above was passed via
                            "Add another account". With no accounts yet there is
                            nowhere to go. */}
                        {(!isFreePlan ||
                            hasCreatedAccounts ||
                            hasExistingAccounts) && (
                            <StepButton
                                text={
                                    isFreePlan
                                        ? __('Back to accounts')
                                        : __('Back')
                                }
                                variant="ghost"
                                onClick={() =>
                                    isFreePlan
                                        ? setIsAddingAnother(false)
                                        : setMode('select')
                                }
                            />
                        )}
                    </>
                }
            >
                <form
                    id="onboarding-account"
                    onSubmit={handleSubmit}
                    autoFocus
                    className="flex flex-col gap-4"
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
                        <p className="flex items-center gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
                            <AlertCircle className="size-4 shrink-0" />
                            {error}
                        </p>
                    )}
                </form>
            </StepScreen>
        );
    }

    // How to set the account up. Tapping a row commits the choice, so the plan
    // price has to be readable inside the row itself.
    return (
        <StepScreen
            title={title}
            description={__('How would you like to set up this account?')}
        >
            <div className="flex flex-col gap-5">
                <StepList>
                    <StepRow
                        icon={Zap}
                        title={__('Connected')}
                        description={__(
                            'Auto-sync transactions directly from your bank',
                        )}
                        badge={
                            subscriptionsEnabled ? (
                                <StepBadge>Standard</StepBadge>
                            ) : undefined
                        }
                        meta={connectedPlanNotice}
                        trailing="chevron"
                        onClick={() => selectMode('connected')}
                    />
                    <StepRow
                        icon={PencilLine}
                        title={__('Manual')}
                        description={__(
                            'Enter transactions yourself or import a CSV file',
                        )}
                        trailing="chevron"
                        onClick={() => selectMode('manual')}
                    />
                </StepList>

                {(hasCreatedAccounts || hasExistingAccounts) && (
                    <StepButton
                        text={__('Back to accounts')}
                        variant="ghost"
                        onClick={() => setIsAddingAnother(false)}
                    />
                )}
            </div>
        </StepScreen>
    );
}
