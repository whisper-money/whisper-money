import {
    evaluateRulesForNewTransaction,
    type NewTransactionData,
} from '@/lib/rule-engine';
import type { AutomationRule } from '@/types/automation-rule';
import type { UUID } from '@/types/uuid';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';

// The engine logs verbose evaluation traces through consoleDebug, which reads
// localStorage; stub it so the parity cases exercise pure rule evaluation.
vi.mock('@/lib/debug', () => ({ consoleDebug: () => {} }));

interface ParityFixture {
    name: string;
    rule: Record<string, unknown>;
    transaction: {
        description: string;
        amount: number;
        creditor_name: string | null;
        debtor_name: string | null;
        notes: string | null;
    };
    expected: boolean;
}

// The same fixtures drive the PHP RuleEngineParityTest, so both engines must
// agree on every case here — locking client and server rule evaluation together.
const fixtures = JSON.parse(
    readFileSync(
        resolve(process.cwd(), 'tests/Fixtures/rule-engine-parity.json'),
        'utf-8',
    ),
) as ParityFixture[];

function makeRule(rulesJson: Record<string, unknown>): AutomationRule {
    return {
        id: 'rule-1' as UUID,
        user_id: 'user-1' as UUID,
        title: 'Parity rule',
        priority: 1,
        origin: 'user',
        rules_json: rulesJson,
        action_category_id: 'cat-1' as UUID,
        action_note: null,
        action_note_iv: null,
        labels: [],
        created_at: '2026-01-01T00:00:00.000Z',
        updated_at: '2026-01-01T00:00:00.000Z',
        deleted_at: null,
    };
}

describe('rule engine PHP/TS parity', () => {
    it.each(fixtures)('$name', async (fixture) => {
        const transactionData: NewTransactionData = {
            description: fixture.transaction.description,
            // Fixture amounts are already in dollars, the unit the client engine
            // compares directly for a new transaction.
            amount: fixture.transaction.amount,
            transaction_date: '2026-01-01',
            account_id: 'acc-1' as UUID,
            notes: fixture.transaction.notes ?? undefined,
            creditor_name: fixture.transaction.creditor_name,
            debtor_name: fixture.transaction.debtor_name,
        };

        const result = await evaluateRulesForNewTransaction(
            transactionData,
            [makeRule(fixture.rule)],
            [],
            [],
            [],
            null,
        );

        expect(result !== null).toBe(fixture.expected);
    });
});
