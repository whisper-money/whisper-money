import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import { StepCallout, StepScreen } from '@/components/onboarding/step-screen';
import { cn } from '@/lib/utils';
import {
    accountIconByType,
    formatAccountType,
    isTransactionalAccount,
    type Account,
} from '@/types/account';
import { type UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';
import { Check } from 'lucide-react';

/** A file belongs to exactly one account, and not to every account. */
export interface ImportTarget {
    account: Account;
    eligible: boolean;
    description: string;
}

/**
 * Which accounts a file can be imported into, and why the others cannot.
 *
 * A connected account is left out on purpose: the bank is already sending its
 * movements, so a file on top of that is how someone ends up with a year of
 * their history twice over. An account that holds a balance rather than
 * movements has nowhere to put the rows at all.
 */
export function importTargets(accounts: Account[]): ImportTarget[] {
    return accounts
        .filter((account) => !account.archived_at)
        .map((account) => {
            const bank = account.bank?.name ?? formatAccountType(account.type);

            if (!isTransactionalAccount(account)) {
                return {
                    account,
                    eligible: false,
                    description: __("Balance only — can't take movements"),
                };
            }

            if (account.banking_connection_id !== null) {
                return {
                    account,
                    eligible: false,
                    description: __(':bank · syncing from the bank', {
                        bank,
                    }),
                };
            }

            return {
                account,
                eligible: true,
                description: `${bank} · ${account.currency_code}`,
            };
        });
}

/** The box that carries the choice, filled once this is the account. */
function SelectionMark({ selected }: { selected: boolean }) {
    return (
        <span
            className={cn(
                'flex size-6 shrink-0 items-center justify-center rounded-md',
                selected ? 'bg-primary' : 'border',
            )}
        >
            {selected && (
                <Check className="size-[15px] text-primary-foreground" />
            )}
        </span>
    );
}

interface StepImportPickAccountProps {
    targets: ImportTarget[];
    selectedAccountId: UUID | null;
    onSelect: (accountId: UUID) => void;
    onContinue: () => void;
}

export function StepImportPickAccount({
    targets,
    selectedAccountId,
    onSelect,
    onContinue,
}: StepImportPickAccountProps) {
    const hasConnected = targets.some(
        (target) => target.account.banking_connection_id !== null,
    );

    return (
        <StepScreen
            title={__('Which account is this file from?')}
            description={__(
                'You have more than one now, so we need to know where these movements go.',
            )}
            footer={
                <StepButton
                    text={__('Continue')}
                    disabled={!selectedAccountId}
                    onClick={onContinue}
                />
            }
        >
            <div className="flex flex-col gap-6">
                <StepList>
                    {targets.map(({ account, eligible, description }) => {
                        const selected = account.id === selectedAccountId;

                        return (
                            <StepRow
                                key={account.id}
                                icon={accountIconByType(account.type)}
                                title={
                                    <span
                                        className={cn(
                                            !eligible &&
                                                'text-muted-foreground',
                                        )}
                                    >
                                        {account.name || __('Account')}
                                    </span>
                                }
                                description={description}
                                trailing={
                                    eligible ? (
                                        <SelectionMark selected={selected} />
                                    ) : (
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            {__('Not eligible')}
                                        </span>
                                    )
                                }
                                pressed={eligible ? selected : undefined}
                                onClick={
                                    eligible
                                        ? () => onSelect(account.id)
                                        : undefined
                                }
                            />
                        );
                    })}
                </StepList>

                {hasConnected && (
                    <StepCallout>
                        {__(
                            'A connected account already gets its movements from the bank. Importing a file on top of one is how you end up with everything twice.',
                        )}
                    </StepCallout>
                )}
            </div>
        </StepScreen>
    );
}
