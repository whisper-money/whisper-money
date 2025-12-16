import { decrypt } from '@/lib/crypto';
import { consoleDebug } from '@/lib/debug';
import type { Account, Bank } from '@/types/account';
import type { AutomationRule } from '@/types/automation-rule';
import type { Category } from '@/types/category';
import type { Label } from '@/types/label';
import type { DecryptedTransaction } from '@/types/transaction';
import type { UUID } from '@/types/uuid';
import jsonLogic from 'json-logic-js';

export interface RuleEvaluationResult {
    rule: AutomationRule;
    categoryId: UUID | null;
    labelIds: UUID[];
    labels: Label[];
    note: string | null;
    noteIv: string | null;
}

export interface TransactionData {
    description: string;
    amount: number;
    transaction_date: string;
    bank_name: string;
    account_name: string;
    category: string | null;
    notes: string | null;
}

function normalizeRuleJson(rulesJson: unknown): unknown {
    if (typeof rulesJson === 'string') {
        return rulesJson.toLowerCase();
    }

    if (Array.isArray(rulesJson)) {
        return rulesJson.map((item, index) => {
            if (index === 0 && typeof item === 'string') {
                return item.toLowerCase();
            }
            if (
                typeof item === 'object' &&
                item !== null &&
                'var' in item &&
                (item.var === 'description' || item.var === 'notes')
            ) {
                return item;
            }
            return normalizeRuleJson(item);
        });
    }

    if (typeof rulesJson === 'object' && rulesJson !== null) {
        const normalized: Record<string, unknown> = {};
        for (const [key, value] of Object.entries(rulesJson)) {
            normalized[key] = normalizeRuleJson(value);
        }
        return normalized;
    }

    return rulesJson;
}

async function decryptAccountName(
    account: Account,
    key: CryptoKey,
): Promise<string> {
    try {
        const decryptedAccountName = await decrypt(
            account.name,
            key,
            account.name_iv,
        );
        return decryptedAccountName.trim();
    } catch (error) {
        console.error('Failed to decrypt account name:', account.id, error);
        return '';
    }
}

const normalizeWhitespace = (str: string): string => {
    return str.trim().replace(/\s+/g, ' ');
};

export async function prepareTransactionData(
    transaction: DecryptedTransaction,
    accounts: Account[],
    banks: Bank[],
    categories: Category[],
    encryptionKey: CryptoKey,
): Promise<TransactionData> {
    const account = accounts.find((a) => a.id === transaction.account_id);
    const bank = account?.bank?.id
        ? banks.find((b) => b.id === account.bank.id)
        : undefined;
    const category = transaction.category_id
        ? categories.find((c) => c.id === transaction.category_id)
        : null;
    const accountName = account
        ? await decryptAccountName(account, encryptionKey)
        : '';

    return {
        description: normalizeWhitespace(
            (transaction.decryptedDescription || '').toLowerCase(),
        ),
        amount: transaction.amount / 100,
        transaction_date: transaction.transaction_date,
        bank_name: (bank?.name || '').toLowerCase(),
        account_name: accountName.toLowerCase(),
        category: category?.name || null,
        notes: transaction.decryptedNotes
            ? normalizeWhitespace(transaction.decryptedNotes.toLowerCase())
            : null,
    };
}

export async function evaluateRules(
    transaction: DecryptedTransaction,
    rules: AutomationRule[],
    categories: Category[],
    accounts: Account[],
    banks: Bank[],
    encryptionKey: CryptoKey,
): Promise<RuleEvaluationResult | null> {
    const sortedRules = [...rules].sort((a, b) => a.priority - b.priority);

    const transactionData = await prepareTransactionData(
        transaction,
        accounts,
        banks,
        categories,
        encryptionKey,
    );

    consoleDebug('[Rule Engine] Transaction data prepared:', transactionData);
    consoleDebug(`[Rule Engine] Evaluating ${sortedRules.length} rules`);

    for (const rule of sortedRules) {
        try {
            consoleDebug(
                `[Rule Engine] Evaluating rule #${rule.id}: "${rule.title}"`,
            );
            consoleDebug('[Rule Engine] Rule JSON:', rule.rules_json);

            const normalizedRulesJson = normalizeRuleJson(rule.rules_json);
            consoleDebug(
                '[Rule Engine] Normalized Rule JSON:',
                normalizedRulesJson,
            );

            const result = jsonLogic.apply(
                normalizedRulesJson,
                transactionData,
            );

            consoleDebug(`[Rule Engine] Rule #${rule.id} result:`, result);

            if (result === true) {
                consoleDebug(`[Rule Engine] ✓ Rule #${rule.id} matched!`);
                return {
                    rule,
                    categoryId: rule.action_category_id,
                    labelIds: rule.labels?.map((l) => l.id) || [],
                    labels: rule.labels || [],
                    note: rule.action_note,
                    noteIv: rule.action_note_iv,
                };
            }
        } catch (error) {
            consoleDebug(
                `[Rule Engine] ❌ Error evaluating rule ${rule.id}:`,
                error,
            );
            console.error(`Error evaluating rule ${rule.id}:`, error);
        }
    }

    consoleDebug('[Rule Engine] No rules matched');
    return null;
}

export async function evaluateRulesForTransactions(
    transactions: DecryptedTransaction[],
    rules: AutomationRule[],
    categories: Category[],
    accounts: Account[],
    banks: Bank[],
    encryptionKey: CryptoKey,
): Promise<Map<string, RuleEvaluationResult>> {
    const results = new Map<string, RuleEvaluationResult>();

    for (const transaction of transactions) {
        const result = await evaluateRules(
            transaction,
            rules,
            categories,
            accounts,
            banks,
            encryptionKey,
        );

        if (result) {
            results.set(transaction.id, result);
        }
    }

    return results;
}

export interface NewTransactionData {
    description: string;
    amount: number;
    transaction_date: string;
    account_id: UUID;
    notes?: string;
}

export async function evaluateRulesForNewTransaction(
    transactionData: NewTransactionData,
    rules: AutomationRule[],
    categories: Category[],
    accounts: Account[],
    banks: Bank[],
    encryptionKey: CryptoKey,
): Promise<RuleEvaluationResult | null> {
    if (!rules || !categories || !accounts || !banks) {
        consoleDebug(
            '[Rule Engine] Missing required data for rule evaluation',
            {
                hasRules: !!rules,
                rulesLength: rules?.length,
                hasCategories: !!categories,
                categoriesLength: categories?.length,
                hasAccounts: !!accounts,
                accountsLength: accounts?.length,
                hasBanks: !!banks,
                banksLength: banks?.length,
            },
        );
        return null;
    }

    const sortedRules = [...rules].sort((a, b) => a.priority - b.priority);

    const account = accounts.find((a) => a.id === transactionData.account_id);
    const bank = account?.bank?.id
        ? banks.find((b) => b.id === account.bank.id)
        : undefined;

    const accountName = account
        ? await decryptAccountName(account, encryptionKey)
        : '';

    const preparedData: TransactionData = {
        description: normalizeWhitespace(
            transactionData.description.toLowerCase(),
        ),
        amount: transactionData.amount,
        transaction_date: transactionData.transaction_date,
        bank_name: (bank?.name || '').toLowerCase(),
        account_name: accountName.toLowerCase(),
        category: null,
        notes: transactionData.notes
            ? normalizeWhitespace(transactionData.notes.toLowerCase())
            : null,
    };

    consoleDebug(
        '[Rule Engine] Evaluating new transaction data:',
        preparedData,
    );
    consoleDebug(`[Rule Engine] Evaluating ${sortedRules.length} rules`);

    for (const rule of sortedRules) {
        try {
            consoleDebug(
                `[Rule Engine] Evaluating rule #${rule.id}: "${rule.title}"`,
            );
            consoleDebug('[Rule Engine] Rule JSON:', rule.rules_json);

            const normalizedRulesJson = normalizeRuleJson(rule.rules_json);
            consoleDebug(
                '[Rule Engine] Normalized Rule JSON:',
                normalizedRulesJson,
            );

            const result = jsonLogic.apply(normalizedRulesJson, preparedData);

            consoleDebug(`[Rule Engine] Rule #${rule.id} result:`, result);

            if (result === true) {
                consoleDebug(`[Rule Engine] ✓ Rule #${rule.id} matched!`);
                return {
                    rule,
                    categoryId: rule.action_category_id,
                    labelIds: rule.labels?.map((l) => l.id) || [],
                    labels: rule.labels || [],
                    note: rule.action_note,
                    noteIv: rule.action_note_iv,
                };
            }
        } catch (error) {
            consoleDebug(
                `[Rule Engine] ❌ Error evaluating rule ${rule.id}:`,
                error,
            );
            console.error(`Error evaluating rule ${rule.id}:`, error);
        }
    }

    consoleDebug('[Rule Engine] No rules matched');
    return null;
}
