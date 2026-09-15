import { store } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { store as storeBank } from '@/actions/App/Http/Controllers/Settings/BankController';
import {
    AccountForm,
    AccountFormData,
} from '@/components/accounts/account-form';
import { StepButton } from '@/components/onboarding/step-button';
import {
    StepError,
    StepScreen,
    stepFormClass,
} from '@/components/onboarding/step-screen';
import { CreatedAccount } from '@/hooks/use-onboarding-state';
import { getCsrfToken } from '@/lib/csrf';
import { captureEvent } from '@/lib/posthog';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { useCallback, useRef, useState } from 'react';

interface StepManualAccountProps {
    banks: { id: string; name: string; logo: string | null }[];
    /** Narrows the currency list to the ones worth offering first. */
    isFirstAccount: boolean;
    onAccountCreated: (account: CreatedAccount) => void;
    /** Absent when there is genuinely nowhere to go back to. */
    onBack?: () => void;
}

/**
 * The by-hand way into the accounts hub: everything open banking will not give
 * you — a mortgage, a property, a cash pot — plus a plain account for anyone who
 * would rather not connect a bank at all.
 *
 * Lifted out of the hub unchanged when the hub replaced the old chooser. PRs F
 * and G rebuild what happens in here; the hub only needs it to report the
 * account it made and hand control back.
 */
export function StepManualAccount({
    banks,
    isFirstAccount,
    onAccountCreated,
    onBack,
}: StepManualAccountProps) {
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
                } else if (bankId) {
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

            captureEvent('onboarding_account_created', {
                account_type: type,
            });

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

    return (
        <StepScreen
            title={
                isFirstAccount
                    ? __('Add it yourself')
                    : __('What else should be in the picture?')
            }
            description={
                isFirstAccount
                    ? __(
                          'Name it, say what it is, and what it’s worth today. That is the whole form.',
                      )
                    : __(
                          'Another account, a mortgage, a pension, a cash pot — the same three answers as before.',
                      )
            }
            footer={
                <>
                    <StepButton
                        type="submit"
                        form="onboarding-account"
                        disabled={isSubmitting}
                        loading={isSubmitting}
                        loadingText={__('Adding…')}
                        text={__('Add this account')}
                    />
                    {onBack && (
                        <StepButton
                            text={__('Back')}
                            variant="ghost"
                            onClick={onBack}
                        />
                    )}
                </>
            }
        >
            <form
                id="onboarding-account"
                onSubmit={handleSubmit}
                autoFocus
                className={cn('flex flex-col gap-4', stepFormClass)}
            >
                <AccountForm
                    onChange={handleFormChange}
                    usePrimaryCurrenciesOnly={isFirstAccount}
                />

                {error && <StepError>{error}</StepError>}
            </form>
        </StepScreen>
    );
}
