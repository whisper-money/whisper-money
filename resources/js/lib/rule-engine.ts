import { consoleDebug } from '@/lib/debug';
import type { Account, Bank } from '@/types/account';
import type { AutomationRule } from '@/types/automation-rule';
import type { Category } from '@/types/category';
import type { DecryptedTransaction } from '@/types/transaction';
import jsonLogic from 'json-logic-js';

export interface RuleEvaluationResult {
    rule: AutomationRule;
    categoryId: number | null;
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

export function prepareTransactionData(
    transaction: DecryptedTransaction,
    accounts: Account[],
    banks: Bank[],
    categories: Category[],
): TransactionData {
    const account = accounts.find((a) => a.id === transaction.account_id);
    const bank = account?.bank?.id
        ? banks.find((b) => b.id === account.bank.id)
        : undefined;
    const category = transaction.category_id
        ? categories.find((c) => c.id === transaction.category_id)
        : null;

    const normalizeWhitespace = (str: string): string => {
        return str.trim().replace(/\s+/g, ' ');
    };

    return {
        description: normalizeWhitespace((transaction.decryptedDescription || '').toLowerCase()),
        amount: transaction.amount / 100,
        transaction_date: transaction.transaction_date,
        bank_name: bank?.name || '',
        account_name: account?.name || '',
        category: category?.name || null,
        notes: transaction.decryptedNotes ? normalizeWhitespace(transaction.decryptedNotes.toLowerCase()) : null,
    };
}

export function evaluateRules(
    transaction: DecryptedTransaction,
    rules: AutomationRule[],
    categories: Category[],
    accounts: Account[],
    banks: Bank[],
): RuleEvaluationResult | null {
    const sortedRules = [...rules].sort((a, b) => a.priority - b.priority);

    const transactionData = prepareTransactionData(
        transaction,
        accounts,
        banks,
        categories,
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

export function evaluateRulesForTransactions(
    transactions: DecryptedTransaction[],
    rules: AutomationRule[],
    categories: Category[],
    accounts: Account[],
    banks: Bank[],
): Map<string, RuleEvaluationResult> {
    const results = new Map<string, RuleEvaluationResult>();

    for (const transaction of transactions) {
        const result = evaluateRules(
            transaction,
            rules,
            categories,
            accounts,
            banks,
        );

        if (result) {
            results.set(transaction.id, result);
        }
    }

    return results;
}
