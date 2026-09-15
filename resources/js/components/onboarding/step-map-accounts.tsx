import { BankLogo } from '@/components/bank-logo';
import { StepButton } from '@/components/onboarding/step-button';
import {
    StepCheck,
    StepList,
    StepRow,
} from '@/components/onboarding/step-list';
import { StepNote, StepScreen } from '@/components/onboarding/step-screen';
import { usableCurrency } from '@/lib/banking-connections';
import { captureEvent } from '@/lib/posthog';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import { toast } from 'sonner';

/** One account the bank handed back, before the user has said yes to it. */
export interface DiscoveredAccount {
    uid: string;
    name: string | null;
    iban: string | null;
    currency: string | null;
}

export interface PendingMapping {
    connection_id: string;
    bank_name: string;
    bank_logo: string | null;
    accounts: DiscoveredAccount[];
}

interface StepMapAccountsProps {
    pending: PendingMapping;
}

/** The last four of an IBAN, which is all of it worth showing on a phone. */
function accountMeta(account: DiscoveredAccount): string {
    const currency = usableCurrency(account.currency);
    const tail = account.iban ? `•••• ${account.iban.slice(-4)}` : null;

    return [tail, currency].filter(Boolean).join(' · ');
}

/**
 * The one decision a bank connection leaves the user: which of the accounts it
 * returned are worth tracking.
 *
 * Onboarding used to take all of them without asking, which is how a joint
 * account nobody wanted in their picture ended up in it. The screen posts to the
 * same endpoint the settings mapping screen does, so "skip" means here what it
 * means there — nothing is created, and the account can be brought in later.
 */
export function StepMapAccounts({ pending }: StepMapAccountsProps) {
    const [selected, setSelected] = useState<Set<string>>(
        () => new Set(pending.accounts.map((account) => account.uid)),
    );
    const [processing, setProcessing] = useState(false);

    const toggle = useCallback((uid: string) => {
        setSelected((current) => {
            const next = new Set(current);

            if (!next.delete(uid)) {
                next.add(uid);
            }

            return next;
        });
    }, []);

    const mappings = useMemo(
        () =>
            pending.accounts.map((account) => ({
                bank_account_uid: account.uid,
                action: selected.has(account.uid) ? 'create' : 'skip',
                existing_account_id: null,
            })),
        [pending.accounts, selected],
    );

    const submit = useCallback(() => {
        setProcessing(true);

        // How many of the bank's accounts a user actually wants is the whole
        // reason this screen exists, and nothing else can report it: once the
        // mapping is stored, the ones left out leave no trace behind.
        captureEvent('onboarding_bank_accounts_mapped', {
            offered: pending.accounts.length,
            kept: selected.size,
        });

        router.post(
            `/open-banking/connections/${pending.connection_id}/map-accounts`,
            { mappings },
            {
                onError: (errors) =>
                    toast.error(
                        Object.values(errors)[0] ?? __('Something went wrong.'),
                    ),
                onFinish: () => setProcessing(false),
            },
        );
    }, [pending, mappings, selected.size]);

    return (
        <StepScreen
            icon={
                <BankLogo
                    src={pending.bank_logo}
                    name={pending.bank_name}
                    fallback="letter"
                    className="size-11 rounded-lg text-base"
                />
            }
            title={
                pending.accounts.length === 1
                    ? __(':bank gave us one account', {
                          bank: pending.bank_name,
                      })
                    : __(':bank gave us :count accounts', {
                          bank: pending.bank_name,
                          count: pending.accounts.length,
                      })
            }
            description={__(
                'Leave out anything that would only muddy the picture — a shared account, a card you never use.',
            )}
            footer={
                <StepButton
                    text={
                        selected.size === 1
                            ? __('Track this one')
                            : __('Track these :count', {
                                  count: selected.size,
                              })
                    }
                    loading={processing}
                    loadingText={__('Setting them up...')}
                    disabled={selected.size === 0}
                    onClick={submit}
                />
            }
        >
            <div className="flex flex-col gap-4">
                <StepList>
                    {pending.accounts.map((account) => {
                        const isSelected = selected.has(account.uid);

                        return (
                            <StepRow
                                key={account.uid}
                                title={
                                    account.name ||
                                    __(':bank account', {
                                        bank: pending.bank_name,
                                    })
                                }
                                description={accountMeta(account)}
                                pressed={isSelected}
                                trailing={
                                    isSelected ? (
                                        <StepCheck />
                                    ) : (
                                        <span className="size-5 shrink-0 rounded-sm border" />
                                    )
                                }
                                onClick={() => toggle(account.uid)}
                            />
                        );
                    })}
                </StepList>

                <StepNote>
                    {__(
                        'You can bring the rest in later from Settings. Nothing is lost by leaving them out now.',
                    )}
                </StepNote>
            </div>
        </StepScreen>
    );
}
