import {
    type CashflowSummary,
    type SankeyCategory,
    type SankeyData,
} from '@/hooks/use-cashflow-data';
import { type Category } from '@/types/category';
import { money } from '../../system/fixtures';
import { type VideoLocale } from '../../system/locale';

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

type CategoryFixture = Pick<Category, 'icon' | 'color' | 'type'> & {
    name: Record<VideoLocale, string>;
    amount: number;
};

const INCOME: CategoryFixture[] = [
    {
        name: { es: 'Nómina', en: 'Salary' },
        icon: 'Briefcase',
        color: 'emerald',
        type: 'income',
        amount: 3500,
    },
    {
        name: { es: 'Freelance', en: 'Freelance' },
        icon: 'Coins',
        color: 'teal',
        type: 'income',
        amount: 350,
    },
];

/** Where the month's income went: CURRENT_MONTH's expenses, saved and invested. */
const OUTFLOWS: CategoryFixture[] = [
    {
        name: { es: 'Vivienda', en: 'Housing' },
        icon: 'Home',
        color: 'blue',
        type: 'expense',
        amount: 950,
    },
    {
        name: { es: 'Ahorro', en: 'Savings' },
        icon: 'PiggyBank',
        color: 'green',
        type: 'savings',
        amount: 600,
    },
    {
        name: { es: 'Supermercado', en: 'Groceries' },
        icon: 'ShoppingBasket',
        color: 'amber',
        type: 'expense',
        amount: 420,
    },
    {
        name: { es: 'Inversión', en: 'Investments' },
        icon: 'LineChart',
        color: 'violet',
        type: 'investment',
        amount: 400,
    },
    {
        name: { es: 'Restaurantes', en: 'Eating out' },
        icon: 'Utensils',
        color: 'orange',
        type: 'expense',
        amount: 270,
    },
    {
        name: { es: 'Ocio', en: 'Leisure' },
        icon: 'Clapperboard',
        color: 'pink',
        type: 'expense',
        amount: 220,
    },
    {
        name: { es: 'Transporte', en: 'Transport' },
        icon: 'Bus',
        color: 'cyan',
        type: 'expense',
        amount: 180,
    },
    {
        name: { es: 'Suscripciones', en: 'Subscriptions' },
        icon: 'Repeat',
        color: 'indigo',
        type: 'expense',
        amount: 100,
    },
];

function sankeyCategories(
    fixtures: CategoryFixture[],
    locale: VideoLocale,
): SankeyCategory[] {
    return fixtures.map((fixture, index) => {
        const id = `video-${fixture.type}-${index}`;

        return {
            category_id: id,
            amount: money(fixture.amount),
            category: {
                id,
                name: fixture.name[locale],
                icon: fixture.icon,
                color: fixture.color,
                type: fixture.type,
                cashflow_direction:
                    fixture.type === 'income' ? 'inflow' : 'outflow',
                parent_id: null,
            },
        };
    });
}

const total = (fixtures: CategoryFixture[]): number =>
    money(fixtures.reduce((sum, fixture) => sum + fixture.amount, 0));

/** The Money Flow diagram for CURRENT_MONTH, its hub being what is available. */
export function moneyFlowFor(locale: VideoLocale): SankeyData {
    return {
        income_categories: sankeyCategories(INCOME, locale),
        expense_categories: sankeyCategories(OUTFLOWS, locale),
        total_income: total(INCOME),
        total_expense: total(OUTFLOWS),
    };
}
