import { store } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { store as storeBank } from '@/actions/App/Http/Controllers/Settings/BankController';
import { IntegrationRequestsDrawer } from '@/components/integration-requests/integration-requests-drawer';
import { ConnectAccountDialog } from '@/components/open-banking/connect-account-dialog';
import { UpgradeConnectionDialog } from '@/components/open-banking/upgrade-connection-dialog';
import { Button } from '@/components/ui/button';
import { CreateButton } from '@/components/ui/create-button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { getCsrfToken } from '@/lib/csrf';
import { SharedData } from '@/types';
import { Account } from '@/types/account';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import { Link2, PenLine } from 'lucide-react';
import React, { useCallback, useMemo, useRef, useState } from 'react';
import { AccountForm, AccountFormData } from './account-form';

type Mode = 'choice' | 'manual';

export function CreateAccountDialog({
    onSuccess,
    trigger,
}: {
    onSuccess?: () => void;
    trigger?: React.ReactNode;
}) {
    const {
        auth,
        subscriptionsEnabled,
        accounts: sharedAccounts,
    } = usePage<SharedData>().props;
    const isFreePlan = subscriptionsEnabled && !auth?.hasProPlan;
    const sharedAccountsList = useMemo(
        () => (sharedAccounts as Account[]) || [],
        [sharedAccounts],
    );
    const availableLoanAccounts = useMemo(
        () => sharedAccountsList.filter((a) => a.type === 'loan'),
        [sharedAccountsList],
    );
    const isFirstAccount = sharedAccountsList.length === 0;

    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState<Mode>('choice');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [connectDialogOpen, setConnectDialogOpen] = useState(false);
    const [upgradeDialogOpen, setUpgradeDialogOpen] = useState(false);
    const [integrationDrawerOpen, setIntegrationDrawerOpen] = useState(false);
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

    function handleOpenChange(newOpen: boolean) {
        setOpen(newOpen);
        if (!newOpen) {
            setMode('choice');
        }
    }

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

            router.post(
                store.url(),
                {
                    name: displayName,
                    ...(finalBankId ? { bank_id: finalBankId } : {}),
                    type: type,
                    currency_code: currencyCode,
                    ...(formDataRef.current.balance
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
                },
                {
                    onSuccess: () => {
                        handleOpenChange(false);
                        onSuccess?.();
                    },
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
                    : 'Failed to create account. Please try again.',
            );
            setIsSubmitting(false);
        }
    }

    return (
        <>
            <Dialog open={open} onOpenChange={handleOpenChange}>
                <DialogTrigger asChild>
                    {trigger ?? (
                        <CreateButton data-testid="open-create-account">
                            {__('Create Account')}
                        </CreateButton>
                    )}
                </DialogTrigger>
                <DialogContent hasKeyboard className="sm:max-w-[425px]">
                    <DialogHeader>
                        <DialogTitle>{__('Create Account')}</DialogTitle>
                        <DialogDescription>
                            {mode === 'choice'
                                ? __(
                                      'Add a bank account, loan, or property to your workspace.',
                                  )
                                : __(
                                      'Create a bank account, loan, or property to track it manually.',
                                  )}
                        </DialogDescription>
                    </DialogHeader>

                    {mode === 'choice' && (
                        <div className="grid grid-cols-2 gap-3">
                            <button
                                type="button"
                                className="flex flex-col items-center gap-3 rounded-lg border p-3 pb-6 text-center transition-colors hover:bg-accent"
                                onClick={() => setMode('manual')}
                            >
                                <PenLine className="h-8 w-8 text-muted-foreground" />
                                <div>
                                    <p className="font-medium">
                                        {__('Manual')}
                                    </p>
                                    <p className="text-xs text-balance text-muted-foreground">
                                        {__(
                                            'Create bank accounts, loans, or properties manually and import transactions.',
                                        )}
                                    </p>
                                </div>
                            </button>
                            <button
                                type="button"
                                className="flex flex-col items-center gap-3 rounded-lg border p-3 pb-6 text-center transition-colors hover:bg-accent"
                                onClick={() => {
                                    if (isFreePlan) {
                                        setUpgradeDialogOpen(true);
                                    } else {
                                        handleOpenChange(false);
                                        setConnectDialogOpen(true);
                                    }
                                }}
                            >
                                <Link2 className="h-8 w-8 text-muted-foreground" />
                                <div>
                                    <p className="font-medium">
                                        {__('Connected')}
                                    </p>
                                    <p className="text-xs text-balance text-muted-foreground">
                                        {__(
                                            'Connect your bank and sync transactions automatically.',
                                        )}
                                    </p>
                                </div>
                            </button>
                        </div>
                    )}

                    {mode === 'manual' && (
                        <form onSubmit={handleSubmit} className="space-y-2">
                            <AccountForm
                                onChange={handleFormChange}
                                availableLoanAccounts={availableLoanAccounts}
                                usePrimaryCurrenciesOnly={isFirstAccount}
                            />

                            <div className="flex justify-end gap-2 pt-4">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        setMode('choice');
                                    }}
                                    disabled={isSubmitting}
                                >
                                    {__('Back')}
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={isSubmitting}
                                    data-testid="submit-account"
                                >
                                    {isSubmitting
                                        ? __('Creating...')
                                        : __('Create')}
                                </Button>
                            </div>
                        </form>
                    )}
                </DialogContent>
            </Dialog>

            <ConnectAccountDialog
                open={connectDialogOpen}
                onOpenChange={setConnectDialogOpen}
            />

            <UpgradeConnectionDialog
                open={upgradeDialogOpen}
                onOpenChange={setUpgradeDialogOpen}
            />

            <IntegrationRequestsDrawer
                open={integrationDrawerOpen}
                onOpenChange={setIntegrationDrawerOpen}
            />
        </>
    );
}
