export type Locale = 'en';

export type Copy = {
    hook: { question: string[]; answer: string };
    noTracking: {
        label: string;
        title: string;
        subtitle: string;
        balanceLabel: string;
        leaks: string[];
    };
    spreadsheet: {
        label: string;
        title: string;
        subtitle: string;
        fileName: string;
        timeSpent: string;
        columns: [string, string, string, string];
        rows: [string, string, string, string][];
    };
    turn: { line: string; brand: string };
    sync: {
        title: string;
        subtitle: string;
        pageTitle: string;
        connected: string;
        syncing: string;
        autoCategorized: string;
        columns: [string, string, string];
        transactions: { description: string; category: string; amount: number }[];
    };
    dashboard: {
        title: string;
        subtitle: string;
        pageTitle: string;
        netWorth: string;
        thisMonth: string;
        months: string[];
    };
    budgets: {
        title: string;
        subtitle: string;
        pageTitle: string;
        spentOf: string;
        overBudget: string;
        items: { name: string; spent: number; limit: number }[];
        goal: { name: string; saved: number; target: number; label: string };
    };
    privacy: { title: string; subtitle: string; pills: string[] };
    comparison: {
        title: string;
        columns: [string, string, string];
        rows: { label: string; values: [string, string, string] }[];
    };
    cta: { title: string; button: string; footnote: string; url: string };
};

const en: Copy = {
    hook: {
        question: ['Where', 'did', 'your', 'money', 'go', 'last', 'month?'],
        answer: 'Most people can only guess.',
    },
    noTracking: {
        label: 'Not tracking',
        title: 'Not tracking means guessing.',
        subtitle: 'Subscriptions pile up. Savings don’t.',
        balanceLabel: 'Spent this month',
        leaks: [
            'Streaming · €15.99',
            'Gym you never go to · €39',
            'Food delivery · €27.40',
            'App subscription · €9.99',
            'Taxi · €18.20',
            'Coffee · €4.50',
        ],
    },
    spreadsheet: {
        label: 'Manual spreadsheet',
        title: 'Spreadsheets take hours.',
        subtitle: 'And they break when you need them most.',
        fileName: 'budget_2026_FINAL_v3.xlsx',
        timeSpent: 'Time spent this month',
        columns: ['Date', 'Description', 'Category', 'Amount'],
        rows: [
            ['03/10', 'Supermarket', 'Food', '-64.20'],
            ['04/10', 'Rent', 'Home', '-950.00'],
            ['06/10', 'Salary', 'Income', '2,850.00'],
            ['07/10', 'Gas station', '???', '-48.90'],
            ['09/10', 'Pharmacy', 'Health', '-12.35'],
            ['11/10', 'Restaurant', 'Food', '-38.00'],
        ],
    },
    turn: { line: 'There’s a better way.', brand: 'Whisper Money' },
    sync: {
        title: 'Connect your banks.',
        subtitle: 'Every transaction syncs and sorts itself.',
        pageTitle: 'Transactions',
        connected: 'Connected',
        syncing: 'Syncing',
        autoCategorized: 'Auto-categorized. Zero typing.',
        columns: ['Category', 'Description', 'Amount'],
        transactions: [
            { description: 'Salary', category: 'Salary', amount: 2850 },
            { description: 'Mercadona', category: 'Groceries', amount: -64.2 },
            { description: 'Netflix', category: 'Online services', amount: -15.99 },
            { description: 'Repsol', category: 'Car & fuel', amount: -48.9 },
            { description: 'Cinema tickets', category: 'Entertainment', amount: -22 },
            { description: 'Farmacia', category: 'Health', amount: -12.35 },
        ],
    },
    dashboard: {
        title: 'See the whole picture.',
        subtitle: 'Net worth, cashflow and every account, at a glance.',
        pageTitle: 'Dashboard',
        netWorth: 'Net Worth Evolution',
        thisMonth: 'this month',
        months: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
    },
    budgets: {
        title: 'Budgets that keep you on track.',
        subtitle: 'Get warned before you overspend, not after.',
        pageTitle: 'Budgets',
        spentOf: 'of',
        overBudget: 'over budget',
        items: [
            { name: 'Groceries', spent: 312, limit: 450 },
            { name: 'Entertainment', spent: 112, limit: 100 },
            { name: 'Eating out', spent: 96, limit: 180 },
        ],
        goal: { name: 'Trip to Japan', saved: 2560, target: 4000, label: 'Saved' },
    },
    privacy: {
        title: 'Private by design.',
        subtitle: 'Your financial data belongs to you.',
        pills: ['Never shared', 'Never sold', 'Open source'],
    },
    comparison: {
        title: 'Stop guessing. Stop typing.',
        columns: ['Not tracking', 'Spreadsheet', 'Whisper Money'],
        rows: [
            { label: 'Know where your money goes', values: ['no', 'partial', 'yes'] },
            { label: 'Time per month', values: ['—', 'Hours', 'Minutes'] },
            { label: 'Automatic bank sync', values: ['no', 'no', 'yes'] },
            { label: 'Budgets & alerts', values: ['no', 'Manual', 'yes'] },
            { label: 'All accounts in one place', values: ['no', 'partial', 'yes'] },
        ],
    },
    cta: {
        title: 'All your money in one place.',
        button: 'Get started for free',
        footnote: 'Free forever. Upgrade anytime.',
        url: 'whisper.money',
    },
};

export const copy: Record<Locale, Copy> = { en };
