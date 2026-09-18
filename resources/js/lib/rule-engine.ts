import { consoleDebug } from '@/lib/debug';
import type { Account, Bank } from '@/types/account';
import type { AutomationRule } from '@/types/automation-rule';
import type { Category } from '@/types/category';
import type { Label } from '@/types/label';
import type { UUID } from '@/types/uuid';
import jsonLogic from 'json-logic-js';

export interface RuleEvaluationResult {
    rule: AutomationRule;
    categoryId: UUID | null;
    labelIds: UUID[];
    labels: Label[];
    note: string | null;
}

export interface TransactionData {
    description: string;
    amount: number;
    transaction_date: string;
    bank_name: string;
    account_name: string;
    category: string | null;
    notes: string | null;
    creditor_name: string | null;
    debtor_name: string | null;
}

function normalizeRuleJson(rulesJson: unknown): unknown {
    if (typeof rulesJson === 'string') {
        try {
            const parsed = JSON.parse(rulesJson);
            if (typeof parsed === 'object' && parsed !== null) {
                return normalizeRuleJson(parsed);
            }
        } catch {
            // Not JSON, treat as a plain string value
        }
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
                (item.var === 'description' ||
                    item.var === 'notes' ||
                    item.var === 'creditor_name' ||
                    item.var === 'debtor_name')
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

const normalizeWhitespace = (str: string): string => {
    return str.trim().replace(/\s+/g, ' ');
};

export interface NewTransactionData {
    description: string;
    amount: number;
    transaction_date: string;
    account_id: UUID;
    notes?: string;
    creditor_name?: string | null;
    debtor_name?: string | null;
}

export function evaluateRulesForNewTransaction(
    transactionData: NewTransactionData,
    rules: AutomationRule[],
    categories: Category[],
    accounts: Account[],
    banks: Bank[],
): RuleEvaluationResult | null {
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

    const accountName = account ? account.name.trim() : '';

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
        creditor_name: transactionData.creditor_name
            ? normalizeWhitespace(transactionData.creditor_name.toLowerCase())
            : null,
        debtor_name: transactionData.debtor_name
            ? normalizeWhitespace(transactionData.debtor_name.toLowerCase())
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
