import { update } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { store as storeBank } from '@/actions/App/Http/Controllers/Settings/BankController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { decrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import type { Account, LoanDetail, RealEstateDetail } from '@/types/account';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    AccountForm,
    AccountFormData,
    LoanFormData,
    RealEstateFormData,
} from './account-form';
import { DeleteAccountDialog } from './delete-account-dialog';

interface AccountWithDetails extends Account {
    loan_detail?: LoanDetail | null;
    real_estate_detail?: RealEstateDetail | null;
}

interface EditAccountDialogProps {
    account: AccountWithDetails;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess?: () => void;
    redirectTo?: string;
    deleteRedirectTo?: string;
}

export function EditAccountDialog({
    account,
    open,
    onOpenChange,
    onSuccess,
    redirectTo,
    deleteRedirectTo,
}: EditAccountDialogProps) {
    const [decryptedName, setDecryptedName] = useState('');
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const formDataRef = useRef<AccountFormData>({
        displayName: '',
        bankId: account.bank?.id ?? null,
        type: account.type,
        currencyCode: account.currency_code,
        customBank: null,
        balance: null,
        realEstate: null,
        loan: null,
    });

    useEffect(() => {
        if (!open) return;

        if (!account.encrypted) {
            setDecryptedName(account.name);
            return;
        }

        async function decryptName() {
            const keyString = getStoredKey();
            if (!keyString) {
                setDecryptedName('[Encrypted]');
                return;
            }

            try {
                const key = await importKey(keyString);
                const name = await decrypt(account.name, key, account.name_iv!);
                setDecryptedName(name);
            } catch (err) {
                console.error('Failed to decrypt account name:', err);
                setDecryptedName('[Encrypted]');
            }
        }

        decryptName();
    }, [open, account.name, account.name_iv, account.encrypted]);

    const loanInitialData: LoanFormData | null = useMemo(() => {
        const detail = account.loan_detail;
        if (!detail) return null;
        return {
            annualInterestRate: detail.annual_interest_rate ?? '',
            loanTermMonths: detail.loan_term_months?.toString() ?? '',
            startDate: detail.start_date?.slice(0, 10) ?? '',
            originalAmount: detail.original_amount ?? 0,
            linkedRealEstateAccountId: null,
        };
    }, [account.loan_detail]);

    const realEstateInitialData: RealEstateFormData | null = useMemo(() => {
        const detail = account.real_estate_detail;
        if (!detail) return null;
        return {
            propertyType: detail.property_type ?? null,
            address: detail.address ?? '',
            purchasePrice: detail.purchase_price ?? 0,
            purchaseDate: detail.purchase_date?.slice(0, 10) ?? '',
            areaValue: detail.area_value ?? '',
            areaUnit: detail.area_unit ?? null,
            linkedLoanAccountId: detail.linked_loan_account_id ?? null,
            notes: detail.notes ?? '',
            revaluationPercentage:
                detail.revaluation_percentage != null
                    ? String(detail.revaluation_percentage)
                    : '',
        };
    }, [account.real_estate_detail]);

    const initialValues = useMemo(
        () =>
            decryptedName && decryptedName !== '[Encrypted]'
                ? {
                      displayName: decryptedName,
                      bank: account.bank,
                      type: account.type,
                      currencyCode: account.currency_code,
                      loan: loanInitialData,
                      realEstate: realEstateInitialData,
                  }
                : undefined,
        [
            decryptedName,
            account.bank,
            account.type,
            account.currency_code,
            loanInitialData,
            realEstateInitialData,
        ],
    );

    const handleFormChange = useCallback((data: AccountFormData) => {
        formDataRef.current = data;
    }, []);

    useEffect(() => {
        if (open) {
            setErrors({});
        }
    }, [open]);

    async function createBankAndGetId(): Promise<string | null> {
        const customBank = formDataRef.current.customBank;
        if (!customBank) return null;

        const formData = new FormData();
        formData.append('name', customBank.name);
        if (customBank.logo) {
            formData.append('logo', customBank.logo);
        }

        try {
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
        } catch (err) {
            console.error('Failed to create bank:', err);
            throw err;
        }
    }

    async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();

        const { displayName, bankId, type, currencyCode, customBank } =
            formDataRef.current;

        if (!type || !currencyCode) {
            alert('Please fill in all required fields.');
            return;
        }

        const isRealEstate = type === 'real_estate';

        setIsSubmitting(true);

        try {
            let finalBankId: string | null = null;

            if (!isRealEstate) {
                if (customBank) {
                    if (!customBank.name.trim()) {
                        alert('Please enter a bank name.');
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
                        alert('Please select a bank.');
                        setIsSubmitting(false);
                        return;
                    }
                    finalBankId = String(bankId);
                }
            }

            router.patch(
                update.url(account.id),
                {
                    name: displayName,
                    ...(finalBankId ? { bank_id: finalBankId } : {}),
                    type: type,
                    currency_code: currencyCode,
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
                                  formDataRef.current.loan.originalAmount ??
                                  null,
                          }
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
                                      .purchasePrice ?? null,
                              purchase_date:
                                  formDataRef.current.realEstate.purchaseDate ||
                                  null,
                              area_value:
                                  formDataRef.current.realEstate.areaValue ||
                                  null,
                              area_unit:
                                  formDataRef.current.realEstate.areaUnit ||
                                  null,
                              linked_loan_account_id:
                                  formDataRef.current.realEstate
                                      .linkedLoanAccountId || null,
                              notes:
                                  formDataRef.current.realEstate.notes || null,
                              revaluation_percentage:
                                  formDataRef.current.realEstate
                                      .revaluationPercentage || null,
                          }
                        : {}),
                },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        setErrors({});
                        onOpenChange(false);
                        if (redirectTo) {
                            router.visit(redirectTo);
                        } else {
                            onSuccess?.();
                        }
                    },
                    onError: (errors) => setErrors(errors),
                    onFinish: () => {
                        setIsSubmitting(false);
                    },
                },
            );
        } catch (err) {
            console.error('Submission failed:', err);
            alert(
                err instanceof Error
                    ? err.message
                    : 'Failed to update account. Please try again.',
            );
            setIsSubmitting(false);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent hasKeyboard className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>{__('Edit Account')}</DialogTitle>
                    <DialogDescription>
                        {__('Update the account information.')}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-2">
                    {initialValues ? (
                        <AccountForm
                            initialValues={initialValues}
                            onChange={handleFormChange}
                            errors={errors}
                        />
                    ) : (
                        <div className="space-y-4">
                            <div className="h-10 animate-pulse rounded bg-muted" />
                            <div className="h-10 animate-pulse rounded bg-muted" />
                            <div className="h-10 animate-pulse rounded bg-muted" />
                            <div className="h-10 animate-pulse rounded bg-muted" />
                        </div>
                    )}

                    <div className="flex items-center justify-between gap-2 pt-4">
                        {deleteRedirectTo ? (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-destructive hover:bg-destructive/10 hover:text-destructive dark:hover:bg-destructive/20"
                                onClick={() => setDeleteOpen(true)}
                                disabled={isSubmitting}
                            >
                                {__('Delete account')}
                            </Button>
                        ) : (
                            <span />
                        )}

                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => onOpenChange(false)}
                                disabled={isSubmitting}
                            >
                                {__('Cancel')}
                            </Button>
                            <Button
                                type="submit"
                                disabled={isSubmitting || !initialValues}
                            >
                                {isSubmitting
                                    ? __('Updating...')
                                    : __('Update')}
                            </Button>
                        </div>
                    </div>
                </form>

                {deleteRedirectTo && (
                    <DeleteAccountDialog
                        account={account}
                        open={deleteOpen}
                        onOpenChange={setDeleteOpen}
                        redirectTo={deleteRedirectTo}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
