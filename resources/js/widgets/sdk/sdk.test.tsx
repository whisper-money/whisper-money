import { setCurrencyDecimals } from '@/utils/currency';
import { fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { widgetSdkModule } from '../runtime/widget-runtime';
import { FORBIDDEN_PROPERTY_NAMES } from '../validator/allowlist';
import { SDK_COMPONENTS, SDK_VALUES } from '../validator/sdk-spec';
import { ChartTooltipContent } from './chart-tooltip';
import { type WidgetEnv, WidgetSdkProvider } from './context';
import {
    beginWidgetGesture,
    csvFilename,
    endWidgetGesture,
    parseCsv,
    sanitizeCsvCell,
} from './download';
import * as sdk from './index';

const env: WidgetEnv = {
    theme: 'light',
    width: '1/2',
    height: 'm',
    isCompact: false,
    privacyMode: false,
    currency: 'EUR',
    locale: 'es-ES',
};

function renderInWidget(
    children: ReactNode,
    overrides: Partial<WidgetEnv> = {},
) {
    return render(
        <WidgetSdkProvider
            value={{
                data: {},
                env: { ...env, ...overrides },
                accounts: {
                    a1: {
                        type: 'credit_card',
                        bankName: 'Banco Uno',
                        bankLogo: 'https://logos.example/uno.png',
                    },
                    a2: {
                        type: 'savings',
                        bankName: null,
                        bankLogo: 'javascript:alert(1)',
                    },
                },
            }}
        >
            {children}
        </WidgetSdkProvider>,
    );
}

describe('the SDK module', () => {
    it('exports exactly what the validator lets widgets import', () => {
        const contract = [...SDK_VALUES, ...Object.keys(SDK_COMPONENTS)].sort();

        expect(Object.keys(sdk).sort()).toEqual(contract);
        expect(Object.keys(widgetSdkModule(sdk)).sort()).toEqual(contract);
        expect(
            Object.values(widgetSdkModule(sdk)).every(
                (value) => value !== undefined,
            ),
        ).toBe(true);
    });
});

describe('get', () => {
    it('reads own properties by a computed key', () => {
        expect(sdk.get({ total: 12 }, 'total')).toBe(12);
        expect(sdk.get(['a', 'b'], 1)).toBe('b');
        expect(sdk.get({ progress: { target: 5 } }.progress, 'target')).toBe(5);
    });

    it('never reaches the prototype', () => {
        expect(sdk.get({}, 'toString')).toBeUndefined();
        expect(sdk.get({}, 'hasOwnProperty')).toBeUndefined();
        expect(sdk.get([], 'map')).toBeUndefined();
    });

    it.each([...FORBIDDEN_PROPERTY_NAMES].filter((name) => name !== 'target'))(
        'refuses %s, even as an own property',
        (key) => {
            const row = JSON.parse(`{"${key}": "x"}`) as object;

            expect(sdk.get(row, key)).toBeUndefined();
            expect(sdk.get({}, key)).toBeUndefined();
        },
    );

    it('returns undefined for anything that is not an object and a key', () => {
        expect(sdk.get(null, 'a')).toBeUndefined();
        expect(sdk.get('text', 'length')).toBeUndefined();
        expect(sdk.get({ a: 1 }, { toString: () => 'a' })).toBeUndefined();
    });
});

describe('formatMoney', () => {
    afterEach(() => setCurrencyDecimals(undefined));

    it('reads minor units at each currency scale', () => {
        setCurrencyDecimals({ EUR: 2, COP: 0, BTC: 8 });

        expect(sdk.formatMoney(123456, 'EUR', { locale: 'es-ES' })).toBe(
            '1.234,56\u202F€',
        );
        expect(sdk.formatMoney(5000, 'COP', { locale: 'en-US' })).toBe(
            'COP\u202F5,000',
        );
        expect(sdk.formatMoney(150000000, 'BTC', { locale: 'en-US' })).toBe(
            'BTC\u202F1.50000000',
        );
    });

    it('writes signs and compact figures', () => {
        expect(
            sdk.formatMoney(1250, 'EUR', { locale: 'en-US', showSign: true }),
        ).toBe('+€12.50');
        expect(
            sdk.formatMoney(-1250, 'EUR', { locale: 'en-US', showSign: true }),
        ).toBe('-€12.50');
        expect(
            sdk.formatMoney(12345600, 'EUR', {
                locale: 'en-US',
                compact: true,
            }),
        ).toBe('€123.5K');
    });

    it('survives bad input instead of throwing', () => {
        expect(sdk.formatMoney('abc', 'EUR')).toBe('');
        expect(sdk.formatMoney(Number.NaN, 'EUR')).toBe('');
        expect(sdk.formatMoney(1250, 'EUR', { locale: 'not a locale!' })).toBe(
            '12.50',
        );
    });
});

describe('<Amount>', () => {
    it('formats in the widget currency and locale', () => {
        const { container } = renderInWidget(<sdk.Amount amount={123456} />);

        expect(container.textContent).toBe('1.234,56\u202F€');
    });

    it('masks the figure while privacy mode is on', () => {
        const { container } = renderInWidget(
            <sdk.Amount amount={123456} currency="USD" />,
            { privacyMode: true },
        );

        expect(container.textContent).not.toMatch(/\d/);
        expect(container.textContent).toContain('*.***,**');
    });
});

describe('<BankLogo>', () => {
    it('draws the bank logo of an account', () => {
        const { container } = renderInWidget(<sdk.BankLogo accountId="a1" />);

        expect(container.querySelector('img')).toHaveAttribute(
            'src',
            'https://logos.example/uno.png',
        );
    });

    it('falls back to the account type icon when the logo is not a safe URL', () => {
        const { container } = renderInWidget(<sdk.BankLogo accountId="a2" />);

        expect(container.querySelector('img')).toBeNull();
        expect(container.querySelector('svg')).not.toBeNull();
    });

    it('draws a generic bank for an id that is not the user’s', () => {
        const { container } = renderInWidget(
            <sdk.BankLogo accountId="__proto__" size={500} />,
        );
        const box = container.querySelector<HTMLElement>(
            '[data-slot="widget-bank-logo"]',
        );

        expect(container.querySelector('img')).toBeNull();
        expect(box?.style.width).toBe('96px');
    });
});

describe('UI primitives', () => {
    it('only forwards the props of their closed list', () => {
        const onClick = vi.fn();
        const Button = sdk.Button as unknown as (
            props: Record<string, unknown>,
        ) => ReactNode;

        renderInWidget(
            <Button
                variant="evil"
                onClick={onClick}
                asChild
                formAction="https://evil.test"
            >
                Go
            </Button>,
        );

        const button = screen.getByRole('button', { name: 'Go' });
        fireEvent.click(button);

        expect(button).toHaveAttribute('type', 'button');
        expect(button).not.toHaveAttribute('formaction');
        // The handler never receives the event.
        expect(onClick).toHaveBeenCalledWith();
    });
});

describe('<ChartTooltip> content', () => {
    const payload = [
        { name: 'Neto', value: 123456, color: 'url(https://evil.test/x)' },
        { name: 'Gasto', value: 500, color: 'var(--color-chart-1)' },
    ];

    it('writes values through the widget formatter, as text', () => {
        const { container } = renderInWidget(
            <ChartTooltipContent
                active
                label="Ene"
                payload={payload}
                valueFormatter={(value: unknown) =>
                    sdk.formatMoney(value, 'EUR', { locale: 'en-US' })
                }
            />,
        );

        expect(container.textContent).toContain('Ene');
        expect(container.textContent).toContain('€1,234.56');
        expect(container.innerHTML).not.toContain('evil.test');
        expect(container.innerHTML).toContain('var(--color-chart-1)');
    });

    it('masks values in privacy mode', () => {
        const { container } = renderInWidget(
            <ChartTooltipContent active label="Ene" payload={payload} />,
            { privacyMode: true },
        );

        expect(container.textContent).toContain('Ene');
        expect(container.textContent).not.toContain('123456');
        expect(container.textContent).toContain('******');
    });

    it('draws nothing while inactive', () => {
        const { container } = renderInWidget(
            <ChartTooltipContent active={false} payload={payload} />,
        );

        expect(container).toBeEmptyDOMElement();
    });
});

function readBlob(blob: Blob): Promise<string> {
    return new Promise((resolve) => {
        const reader = new FileReader();

        reader.onload = () => resolve(String(reader.result));
        reader.readAsText(blob);
    });
}

describe('wm.download', () => {
    let saved: { name: string; text: Promise<string> }[];

    beforeEach(() => {
        saved = [];
        URL.createObjectURL = vi.fn((blob: Blob) => {
            saved.push({ name: '', text: readBlob(blob) });

            return 'blob:widget';
        });
        URL.revokeObjectURL = vi.fn();
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(
            function (this: HTMLAnchorElement) {
                saved[saved.length - 1].name = this.download;
            },
        );
        vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    afterEach(() => {
        endWidgetGesture();
        vi.restoreAllMocks();
    });

    it('refuses to download outside a click', () => {
        expect(sdk.wm.download('data', [['a']])).toBe(false);
        expect(saved).toHaveLength(0);
    });

    it('downloads a CSV with formulas defused inside a click', async () => {
        beginWidgetGesture();

        expect(
            sdk.wm.download('../gastos', [
                { concept: '=HYPERLINK("https://evil.test")', amount: -4210 },
                { concept: '@SUM(A1)', amount: '-12.50' },
                { concept: '+1+1', amount: 3 },
            ]),
        ).toBe(true);

        expect(saved[0].name).toBe('gastos.csv');
        expect(await saved[0].text).toBe(
            // FileReader drops the BOM the file starts with.
            'concept,amount\r\n' +
                '"\'=HYPERLINK(""https://evil.test"")",-4210\r\n' +
                "'@SUM(A1),-12.50\r\n" +
                "'+1+1,3",
        );
    });

    it('defuses every cell of CSV text the widget wrote itself', async () => {
        beginWidgetGesture();
        sdk.wm.download('raw.csv', 'name,note\nA,"=cmd|\' /C calc\'!A0"\nB,-x');

        expect(await saved[0].text).toBe(
            "name,note\r\nA,'=cmd|' /C calc'!A0\r\nB,'-x",
        );
    });

    it('refuses content of the wrong shape, and clicks the page faked', () => {
        beginWidgetGesture();

        expect(sdk.wm.download('mixed', [['a'], { b: 1 }])).toBe(false);
        expect(sdk.wm.download('empty', [])).toBe(false);
        expect(sdk.wm.download('number', 42)).toBe(false);

        endWidgetGesture();
        beginWidgetGesture({ nativeEvent: { isTrusted: false } });

        expect(sdk.wm.download('fake', [['a']])).toBe(false);
        expect(saved).toHaveLength(0);
    });

    it('closes the gesture once the click is over', async () => {
        beginWidgetGesture();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(sdk.wm.download('late', [['a']])).toBe(false);
    });

    it('sanitizes cells and file names', () => {
        expect(sanitizeCsvCell('\tcmd')).toBe("'\tcmd");
        expect(sanitizeCsvCell('-1.234,50')).toBe('-1.234,50');
        expect(sanitizeCsvCell(null)).toBe('');
        expect(sanitizeCsvCell(BigInt(1))).toBe('');
        expect(csvFilename('a/b\\c:d')).toBe('a-b-c-d.csv');
        expect(csvFilename('')).toBe('widget.csv');
        expect(parseCsv('a,"b,c"\r\n"d""e",f')).toEqual([
            ['a', 'b,c'],
            ['d"e', 'f'],
        ]);
    });
});
