import { fixtureGrid } from '@/lib/broker-imports/testing';
import { parseTradeRepublic } from '@/lib/broker-imports/trade-republic';
import {
    defaultTradeRepublicPlan,
    type TradeRepublicImport,
    type TradeRepublicPlan,
} from '@/lib/broker-imports/trade-republic-import';
import type { Account } from '@/types/account';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    ImportStepBrokerTradeRepublic,
    TradeRepublicSummaryStrip,
} from './import-step-broker-trade-republic';

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({ isPrivacyModeEnabled: false }),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en' } }),
}));

const TODAY = '2026-03-02';

const checking: Account = {
    id: 'checking-1',
    name: 'Everyday',
    bank: { id: 'bank-bbva', user_id: null, name: 'BBVA', logo: null },
    type: 'checking',
    currency_code: 'EUR',
    banking_connection_id: null,
    external_account_id: null,
    linked_at: null,
};

async function sampleImport(
    plan: Partial<TradeRepublicPlan> = {},
): Promise<TradeRepublicImport> {
    const file = parseTradeRepublic(
        await fixtureGrid('trade-republic-sample.csv'),
    );

    return {
        kind: 'trade_republic',
        file,
        plan: { ...defaultTradeRepublicPlan(file, [], null), ...plan },
    };
}

function renderStep(
    broker: TradeRepublicImport,
    onPlanChange = vi.fn(),
    accounts: Account[] = [checking],
) {
    render(
        <ImportStepBrokerTradeRepublic
            broker={broker}
            accounts={accounts}
            today={TODAY}
            onPlanChange={onPlanChange}
            onNext={vi.fn()}
            onBack={vi.fn()}
        />,
    );

    return onPlanChange;
}

describe('ImportStepBrokerTradeRepublic', () => {
    it('prefills the cash balance worked out from the file', async () => {
        const broker = await sampleImport({ balanceToday: 123_456 });
        renderStep(broker);

        expect(
            (screen.getByLabelText('Cash balance today') as HTMLInputElement)
                .value,
        ).toBe('1,234.56');
    });

    it('takes an emptied current value as none, so the invested amount stands in', async () => {
        const onPlanChange = renderStep(
            await sampleImport({ currentValue: 5000 }),
        );
        const input = screen.getByLabelText('Current value');

        fireEvent.focus(input);
        fireEvent.change(input, { target: { value: '' } });
        fireEvent.blur(input);

        expect(onPlanChange).toHaveBeenLastCalledWith(
            expect.objectContaining({ currentValue: null }),
        );
    });

    it('hides the value fields when the portfolio is left out', async () => {
        renderStep(await sampleImport({ portfolio: { kind: 'skip' } }));

        expect(screen.queryByLabelText('Current value')).toBeNull();
    });

    it('warns, without blocking, when the cash goes to another bank', async () => {
        renderStep(
            await sampleImport({
                cash: { kind: 'existing', accountId: checking.id },
            }),
        );

        expect(screen.getByText(/which is at BBVA/)).not.toBeNull();
        expect(
            screen
                .getByRole('button', { name: 'Preview' })
                .hasAttribute('disabled'),
        ).toBe(false);
    });

    it('does not warn for a new Trade Republic account', async () => {
        renderStep(await sampleImport());

        expect(screen.queryByText(/Sure the Trade Republic cash/)).toBeNull();
    });
});

describe('TradeRepublicSummaryStrip', () => {
    it('shows the gain only once a value was typed', async () => {
        const { rerender } = render(
            <TradeRepublicSummaryStrip
                broker={await sampleImport()}
                today={TODAY}
            />,
        );

        expect(screen.queryByText('Gain')).toBeNull();

        rerender(
            <TradeRepublicSummaryStrip
                broker={await sampleImport({ currentValue: 30_000 })}
                today={TODAY}
            />,
        );

        expect(screen.getByText('Gain')).not.toBeNull();
        expect(screen.getByText('Portfolio · current value')).not.toBeNull();
    });
});
