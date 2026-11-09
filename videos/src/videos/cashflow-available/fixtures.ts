import { type CashflowSummary } from '@/hooks/use-cashflow-data';
import { money } from '../../system/fixtures';

/** A made-up month: steady salary, a mortgage, groceries, some saved and invested. */
export const CURRENT_MONTH: CashflowSummary = {
    income: money(3850),
    expense: money(2140),
    net: money(1710),
    savings_rate: 44.4,
    savings: money(600),
    investments: money(400),
    reserved: money(180),
    fixed_expense: money(1150),
    variable_expense: money(640),
    general_expense: money(350),
};

export const PREVIOUS_MONTH: CashflowSummary = {
    income: money(3700),
    expense: money(2300),
    net: money(1400),
    savings_rate: 37.8,
    savings: money(500),
    investments: money(300),
    fixed_expense: money(1150),
    variable_expense: money(760),
    general_expense: money(390),
};

/** The month the period selector shows. */
export const SHOWN_MONTH = new Date(2026, 9, 1);
