import { fireEvent, render, screen } from '@testing-library/react';
import { execFile } from 'node:child_process';
import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { promisify } from 'node:util';
import type { ReactNode } from 'react';
import { beforeAll, describe, expect, it } from 'vitest';
import {
    loadWidgetModule,
    registerWidgetModules,
    WidgetModuleError,
} from './load-widget-module';
import { createWidgetModuleRegistry } from './widget-module-registry';

const root = path.resolve(__dirname, '../../..');
const fixtures = path.join(root, 'tests/Fixtures/widgets/valid');

interface CompileResult {
    ok: boolean;
    js: string | null;
    errors: { message: string }[];
}

/** Compile through the real script, the way the PHP service does. */
async function compile(source: string): Promise<string> {
    const child = promisify(execFile)(
        'bun',
        ['scripts/widgets/compile-widget.ts'],
        { cwd: root },
    );

    child.child.stdin?.end(JSON.stringify({ source }));

    const result = JSON.parse((await child).stdout) as CompileResult;

    if (!result.ok || result.js === null) {
        throw new Error(result.errors.map((error) => error.message).join('\n'));
    }

    return result.js;
}

const data = {
    groceries: {
        data: [
            { date: '2026-09-03', amount: -4210 },
            { date: '2026-10-11', amount: -3890 },
        ],
    },
    netWorth: [{ value: 1200000 }, { value: 1250000 }],
    categories: [
        { name: 'Comida', total: 42000 },
        { name: 'Casa', total: 81000 },
    ],
    cashflow: [{ month: '2026-09', net: 12000 }],
    savings: { saved: 30000, goal: 50000 },
    accounts: [{ id: 'a1', name: 'Nómina', balance: 250000, currency: 'EUR' }],
    budgets: [
        { name: 'Ocio', spent: 120, limit: 100 },
        { name: 'Super', spent: 50, limit: 100 },
        { name: 'Ropa', spent: 10, limit: 100 },
    ],
    kpis: { income: 300000, expenses: 210000 },
    labels: [
        { name: 'Viajes', total: 900 },
        { name: 'Regalos', total: 300 },
    ],
};

const Passthrough = ({
    children,
    className,
}: {
    children?: ReactNode;
    className?: string;
}) => <div className={className}>{children}</div>;

/** A stand-in for the SDK T8 builds: same names, minimal behaviour. */
const sdk = {
    useWidgetData: () => data,
    useWidgetEnv: () => ({
        currency: 'EUR',
        privacyMode: false,
        isCompact: false,
        theme: 'light',
    }),
    formatMoney: (amount: number, currency: string) =>
        `${(amount / 100).toFixed(2)} ${currency}`,
    get: (object: Record<string, unknown>, key: string) =>
        Object.hasOwn(object, key) ? object[key] : undefined,
    cn: (...classes: unknown[]) => classes.filter(Boolean).join(' '),
    wm: { download: () => {} },
    Amount: ({ amount, currency }: { amount: number; currency: string }) => (
        <span>{`${amount / 100} ${currency}`}</span>
    ),
    BankLogo: () => <span data-testid="bank-logo" />,
    Badge: Passthrough,
    Progress: ({ value }: { value: number }) => (
        <div role="progressbar" aria-valuenow={value} />
    ),
    Table: ({ children }: { children?: ReactNode }) => (
        <table>{children}</table>
    ),
    TableHeader: ({ children }: { children?: ReactNode }) => (
        <thead>{children}</thead>
    ),
    TableBody: ({ children }: { children?: ReactNode }) => (
        <tbody>{children}</tbody>
    ),
    TableRow: ({ children }: { children?: ReactNode }) => <tr>{children}</tr>,
    TableHead: ({ children }: { children?: ReactNode }) => <th>{children}</th>,
    TableCell: ({ children }: { children?: ReactNode }) => <td>{children}</td>,
};

describe('loadWidgetModule', () => {
    let compiled: Map<string, string>;

    beforeAll(async () => {
        registerWidgetModules(await createWidgetModuleRegistry(sdk));

        const files = (await readdir(fixtures))
            .filter((file) => file.endsWith('.tsx'))
            .sort();
        const sources = await Promise.all(
            files.map((file) => readFile(path.join(fixtures, file), 'utf8')),
        );

        compiled = new Map(
            await Promise.all(
                files.map(
                    async (file, index) =>
                        [file, await compile(sources[index])] as const,
                ),
            ),
        );
    }, 60_000);

    it('renders ten compiled widgets at once on the app React', async () => {
        expect(compiled.size).toBe(10);

        const components = await Promise.all(
            [...compiled.values()].map((js) => loadWidgetModule(js)),
        );

        render(
            <div>
                {components.map((Widget, index) => (
                    <div key={index} className="wm-widget">
                        <Widget />
                    </div>
                ))}
            </div>,
        );

        for (const heading of [
            'Supermercado por mes',
            'Patrimonio neto',
            'Gasto por categoría',
            'Top categorías',
            'Flujo de caja',
            'Ahorro del mes',
            'Cuentas',
            'Presupuestos',
            'Ingresos',
            /Gasto por etiqueta/,
        ]) {
            expect(screen.getByText(heading)).toBeInTheDocument();
        }

        // Hooks only work when the widget's `react` is the app's own instance.
        screen.getByRole('button', { name: 'Ver todos' }).click();
        expect(await screen.findByText('Ropa')).toBeInTheDocument();
    });

    it('freezes the elements a widget creates and hides $$typeof from spreads', async () => {
        const Widget = await loadWidgetModule(
            'import{jsx as j}from"react/jsx-runtime";export default function W(){const e=j("div",{});let mutated=true;try{e.type="iframe"}catch{mutated=false}const copy={...e};return j("span",{children:String(mutated)+":"+String(Object.keys(copy).includes("$$typeof"))})}',
        );

        render(<Widget />);

        expect(screen.getByText('false:false')).toBeInTheDocument();
    });

    it('drops external url() from fill and stroke at runtime', async () => {
        const Widget = await loadWidgetModule(
            'import{jsx as j}from"react/jsx-runtime";export default function W(){return j("svg",{children:[j("rect",{"data-testid":"a",fill:"url(https://evil.test/x)"},"a"),j("rect",{"data-testid":"b",fill:"url(#grad)"},"b")]})}',
        );

        render(<Widget />);

        expect(screen.getByTestId('a')).not.toHaveAttribute('fill');
        expect(screen.getByTestId('b')).toHaveAttribute('fill', 'url(#grad)');
    });

    /**
     * Rows reach Recharts after `.map()`, where the validator cannot see what
     * they hold, and Recharts spreads them onto the SVG it draws, `on*` keys
     * as handlers. Found in review; both compile, so the runtime must hold.
     */
    it.each(['Bar', 'Pie'])(
        'strips handlers and url attributes that chart rows carry into <%s>',
        async (series) => {
            const chart = series === 'Bar' ? 'BarChart' : 'PieChart';
            // BarChart takes the rows itself; Pie takes its own.
            const [chartData, seriesData] =
                series === 'Bar' ? [' data={data}', ''] : ['', ' data={data}'];
            const Widget = await loadWidgetModule(
                await compile(`import { ${chart}, ${series} } from 'recharts';
import { useWidgetData } from '@whisper-money/widget-sdk';
export default function W() {
    const { labels } = useWidgetData();
    const leak = (a, b, c) => {
        for (const x of [a, b, c]) {
            if (x && typeof x === 'object') {
                const w = Object.values(x).find((v) => v && v.document);
                if (w) { w.document.title = 'PWNED'; }
            }
        }
    };
    const u = 'url(https://evil.test/x.svg#a)';
    const data = labels.map((r) => ({ ...r, onClick: leak, onMouseEnter: leak, fill: u, filter: u, mask: u, style: { filter: u }, href: 'https://evil.test/' }));
    return <${chart} width={300} height={200}${chartData}><${series}${seriesData} dataKey="total" isAnimationActive={false} /></${chart}>;
}`),
            );
            const { container } = render(<Widget />);
            const shapes = container.querySelectorAll('path');

            expect(shapes.length).toBeGreaterThan(0);

            shapes.forEach((shape) => {
                fireEvent.mouseEnter(shape);
                fireEvent.click(shape);
            });

            expect(document.title).not.toBe('PWNED');
            expect(container.innerHTML).not.toContain('evil.test');
        },
    );

    it('refuses modules outside the contract', async () => {
        await expect(
            loadWidgetModule(
                'import{x}from"left-pad";export default function W(){}',
            ),
        ).rejects.toThrow(WidgetModuleError);
        await expect(
            loadWidgetModule(
                'import{createElement}from"react";export default function W(){}',
            ),
        ).rejects.toThrow('"createElement" is not available from "react"');
        await expect(
            loadWidgetModule(
                'const a=1;import{useState}from"react";export default function W(){}',
            ),
        ).rejects.toThrow(WidgetModuleError);
        await expect(
            loadWidgetModule('export const notDefault = 1;'),
        ).rejects.toThrow('no default export');
    });
});
