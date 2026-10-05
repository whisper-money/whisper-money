import {
    ChoiceCards,
    Notice,
    Pill,
    SectionCard,
    SectionRow,
    WizardScreen,
    type Choice,
} from '@/components/full-import/full-import-layout';
import { formatCount } from '@/lib/full-import-format';
import { type ContextAccount, type FullImportMode } from '@/types/full-import';
import { __ } from '@/utils/i18n';
import { Lock, RefreshCw } from 'lucide-react';
import { type ReactNode } from 'react';

interface StepExistingProps {
    eyebrow: string;
    accounts: ContextAccount[];
    mode: FullImportMode;
    onModeChange: (mode: FullImportMode) => void;
    locale: string;
    footer: ReactNode;
}

/** "BBVA and Amex", "BBVA, Amex and Cash": a list the way a sentence says it. */
export function joinNames(names: string[], locale: string): string {
    return new Intl.ListFormat(locale, {
        style: 'long',
        type: 'conjunction',
    }).format(names);
}

export function StepExisting({
    eyebrow,
    accounts,
    mode,
    onModeChange,
    locale,
    footer,
}: StepExistingProps) {
    const manual = accounts.filter((account) => !account.connected);
    const connected = accounts.filter((account) => account.connected);
    const manualMovements = manual.reduce(
        (total, account) => total + account.transactions_count,
        0,
    );

    const options: Choice<FullImportMode>[] = [
        {
            value: 'add',
            title: __('Add to what I have'),
            description: __(
                'Everything of yours stays. In the accounts step you can send a file account into one of your manual accounts; repeated transactions are skipped.',
            ),
        },
        {
            value: 'wipe',
            title: __('Start from scratch'),
            badge: <Pill tone="danger">{__('Cannot be undone')}</Pill>,
            description:
                manual.length > 0
                    ? __(
                          'Before importing we delete your manual accounts with their transactions and balances: :accounts, :count transactions. Your categories, labels and rules stay.',
                          {
                              accounts: joinNames(
                                  manual.map((account) => account.name),
                                  locale,
                              ),
                              count: formatCount(manualMovements, locale),
                          },
                      )
                    : __(
                          'You have no manual accounts, so nothing is deleted. Your categories, labels and rules stay.',
                      ),
        },
    ];

    return (
        <WizardScreen
            eyebrow={eyebrow}
            title={__('You already have data here')}
            description={__(
                'Before importing, tell us what to do with what you already have in this space.',
            )}
            footer={footer}
        >
            <SectionCard label={__('In this space you have')}>
                {accounts.map((account) => (
                    <SectionRow
                        key={account.id}
                        title={
                            <>
                                {account.name}
                                {account.connected && (
                                    <Pill>
                                        <RefreshCw className="size-3" />
                                        {__('Connected')}
                                    </Pill>
                                )}
                            </>
                        }
                        meta={[
                            account.connected
                                ? __('Syncs daily')
                                : __('Manual'),
                            __(':count transactions', {
                                count: formatCount(
                                    account.transactions_count,
                                    locale,
                                ),
                            }),
                        ].join(' · ')}
                    />
                ))}
            </SectionCard>

            <ChoiceCards
                legend={__('What do we do with them?')}
                value={mode}
                options={options}
                onChange={onModeChange}
            />

            {connected.length > 0 && (
                <Notice icon={Lock}>
                    {__(
                        ':accounts is connected and never touched. If the file has transactions from it, they go to a new manual account so they never mix with the ones the bank sends.',
                        {
                            accounts: joinNames(
                                connected.map((account) => account.name),
                                locale,
                            ),
                        },
                    )}
                </Notice>
            )}
        </WizardScreen>
    );
}
