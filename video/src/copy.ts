export type Locale = 'en' | 'es';

type BankAccount = { name: string; initials: string };

export type Copy = {
    accounts: { payroll: BankAccount; daily: BankAccount; savings: BankAccount };
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
        total: string;
        formula: string;
        formulaError: string;
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
        change: string;
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
    accounts: {
        payroll: { name: 'Payroll', initials: 'PB' },
        daily: { name: 'Daily', initials: 'DB' },
        savings: { name: 'Savings', initials: 'SV' },
    },
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
        total: 'TOTAL',
        formula: '=SUM(D2:D7',
        formulaError: '#REF!',
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
        change: '+3.2%',
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

const es: Copy = {
    accounts: {
        payroll: { name: 'Nómina', initials: 'NB' },
        daily: { name: 'Diario', initials: 'DB' },
        savings: { name: 'Ahorros', initials: 'AH' },
    },
    hook: {
        question: ['¿Adónde', 'se', 'fue', 'tu', 'dinero', 'el', 'mes', 'pasado?'],
        answer: 'La mayoría solo puede adivinarlo.',
    },
    noTracking: {
        label: 'Sin control',
        title: 'Sin control, vas a ciegas.',
        subtitle: 'Las suscripciones se acumulan. Los ahorros, no.',
        balanceLabel: 'Gastado este mes',
        leaks: [
            'Streaming · 15,99 €',
            'Gimnasio al que no vas · 39 €',
            'Comida a domicilio · 27,40 €',
            'Suscripción a una app · 9,99 €',
            'Taxi · 18,20 €',
            'Café · 4,50 €',
        ],
    },
    spreadsheet: {
        label: 'Excel a mano',
        title: 'Los Excels se comen horas.',
        subtitle: 'Y fallan justo cuando más los necesitas.',
        fileName: 'presupuesto_2026_FINAL_v3.xlsx',
        timeSpent: 'Tiempo invertido este mes',
        columns: ['Fecha', 'Concepto', 'Categoría', 'Importe'],
        rows: [
            ['03/10', 'Supermercado', 'Comida', '-64,20'],
            ['04/10', 'Alquiler', 'Casa', '-950,00'],
            ['06/10', 'Nómina', 'Ingresos', '2.850,00'],
            ['07/10', 'Gasolinera', '???', '-48,90'],
            ['09/10', 'Farmacia', 'Salud', '-12,35'],
            ['11/10', 'Restaurante', 'Comida', '-38,00'],
        ],
        total: 'TOTAL',
        formula: '=SUMA(D2:D7',
        formulaError: '#¡REF!',
    },
    turn: { line: 'Hay una forma mejor.', brand: 'Whisper Money' },
    sync: {
        title: 'Conecta tus bancos.',
        subtitle: 'Cada movimiento se sincroniza y se ordena solo.',
        pageTitle: 'Transacciones',
        connected: 'Conectado',
        syncing: 'Sincronizando',
        autoCategorized: 'Categorías automáticas. Cero tecleo.',
        columns: ['Categoría', 'Concepto', 'Importe'],
        transactions: [
            { description: 'Nómina', category: 'Salario', amount: 2850 },
            { description: 'Mercadona', category: 'Supermercado', amount: -64.2 },
            { description: 'Netflix', category: 'Servicios online', amount: -15.99 },
            { description: 'Repsol', category: 'Coche y gasolina', amount: -48.9 },
            { description: 'Entradas de cine', category: 'Entretenimiento', amount: -22 },
            { description: 'Farmacia', category: 'Salud', amount: -12.35 },
        ],
    },
    dashboard: {
        title: 'Tu dinero, de un vistazo.',
        subtitle: 'Patrimonio, flujo de caja y todas tus cuentas en una pantalla.',
        pageTitle: 'Panel',
        netWorth: 'Evolución del patrimonio neto',
        change: '+3,2\u00a0%',
        thisMonth: 'este mes',
        months: ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'],
    },
    budgets: {
        title: 'Presupuestos que te mantienen a raya.',
        subtitle: 'Te avisamos antes de pasarte, no después.',
        pageTitle: 'Presupuestos',
        spentOf: 'de',
        overBudget: 'sobre el presupuesto',
        items: [
            { name: 'Supermercado', spent: 312, limit: 450 },
            { name: 'Entretenimiento', spent: 112, limit: 100 },
            { name: 'Comer fuera', spent: 96, limit: 180 },
        ],
        goal: { name: 'Viaje a Japón', saved: 2560, target: 4000, label: 'Ahorrado' },
    },
    privacy: {
        title: 'Privado por diseño.',
        subtitle: 'Tus datos financieros son solo tuyos.',
        pills: ['Nunca compartidos', 'Nunca vendidos', 'Código abierto'],
    },
    comparison: {
        title: 'Ni adivinar. Ni teclear.',
        columns: ['Sin control', 'Excel', 'Whisper Money'],
        rows: [
            { label: 'Saber en qué se va tu dinero', values: ['no', 'partial', 'yes'] },
            { label: 'Tiempo al mes', values: ['—', 'Horas', 'Minutos'] },
            { label: 'Sincronización bancaria', values: ['no', 'no', 'yes'] },
            { label: 'Presupuestos y alertas', values: ['no', 'A mano', 'yes'] },
            { label: 'Todas tus cuentas juntas', values: ['no', 'partial', 'yes'] },
        ],
    },
    cta: {
        title: 'Todo tu dinero en un solo sitio.',
        button: 'Empieza gratis',
        footnote: 'Gratis para siempre. Mejora cuando quieras.',
        url: 'whisper.money',
    },
};

export const copy: Record<Locale, Copy> = { en, es };
