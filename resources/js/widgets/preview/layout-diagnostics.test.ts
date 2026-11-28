import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    describe as describeElement,
    layoutDiagnostics,
} from './layout-diagnostics';

/*
 * jsdom lays nothing out, so each test places its elements by hand: the box
 * a browser would give them and, where it matters, their scroll sizes.
 */

interface Box {
    top?: number;
    left?: number;
    width: number;
    height: number;
}

const boxes = new WeakMap<Element, Box>();

function rect({ top = 0, left = 0, width, height }: Box): DOMRect {
    return {
        top,
        left,
        width,
        height,
        bottom: top + height,
        right: left + width,
        x: left,
        y: top,
        toJSON: () => ({}),
    };
}

function place<T extends Element>(element: T, box: Box): T {
    boxes.set(element, box);

    return element;
}

function scrollSize(
    element: Element,
    sizes: Partial<
        Record<
            'scrollWidth' | 'clientWidth' | 'scrollHeight' | 'clientHeight',
            number
        >
    >,
): void {
    for (const [name, value] of Object.entries(sizes)) {
        Object.defineProperty(element, name, { value, configurable: true });
    }
}

function makeCell(html: string, box: Box = { width: 400, height: 256 }) {
    const cell = document.createElement('div');
    cell.style.height = `${box.height}px`;
    cell.innerHTML = html;
    document.body.appendChild(cell);
    place(cell, box);
    scrollSize(cell, {
        scrollWidth: box.width,
        clientWidth: box.width,
        scrollHeight: box.height,
        clientHeight: box.height,
    });

    return cell;
}

beforeEach(() => {
    vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(
        function (this: Element) {
            return rect(boxes.get(this) ?? { width: 0, height: 0 });
        },
    );
    // A text node takes the box of the element that holds it.
    Range.prototype.getBoundingClientRect = function (this: Range) {
        const parent = this.startContainer.parentElement;

        return rect((parent && boxes.get(parent)) ?? { width: 0, height: 0 });
    };
});

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

function kinds(cell: HTMLElement): string[] {
    return layoutDiagnostics(cell).diagnostics.map(({ kind }) => kind);
}

describe('layoutDiagnostics', () => {
    it('finds nothing wrong with a widget that fits its cell', () => {
        const cell = makeCell('<p>Supermercado</p>');
        place(cell.querySelector('p')!, { top: 16, width: 368, height: 200 });

        const report = layoutDiagnostics(cell);

        expect(report.diagnostics).toEqual([]);
        expect(report).toMatchObject({
            content_height: 216,
            cell_height: 256,
            cell_width: 400,
        });
    });

    it('says how much the cell cuts off and which element reaches past it', () => {
        const cell = makeCell('<p class="text-2xl">Fila 12</p>');
        scrollSize(cell, { scrollHeight: 300 });
        place(cell.querySelector('p')!, { top: 240, width: 368, height: 60 });

        const { diagnostics } = layoutDiagnostics(cell);

        expect(diagnostics).toContainEqual({
            kind: 'cell_overflow',
            message:
                'The content is 300px tall but the cell shows 256px: the bottom 44px is cut off.',
        });
        expect(diagnostics).toContainEqual({
            kind: 'clipped_by_cell',
            message: "Reaches 44px past the cell's bottom edge and is cut off.",
            element: 'p.text-2xl "Fila 12"',
        });
    });

    it('reports only the outermost element the cell clips', () => {
        const cell = makeCell('<div class="list"><p>Uno</p><p>Dos</p></div>');
        place(cell.querySelector('.list')!, { width: 400, height: 400 });
        cell.querySelectorAll('p').forEach((p, index) =>
            place(p, { top: index * 200, width: 400, height: 200 }),
        );

        expect(
            kinds(cell).filter((kind) => kind === 'clipped_by_cell'),
        ).toEqual(['clipped_by_cell']);
    });

    it('leaves what scrolls inside its own container alone, but says it scrolls', () => {
        const cell = makeCell(
            '<div class="scroller" style="overflow-y: auto"><p>Uno</p></div>',
        );
        const scroller = place(cell.querySelector<HTMLElement>('.scroller')!, {
            width: 400,
            height: 256,
        });
        scrollSize(scroller, { scrollHeight: 900, clientHeight: 256 });
        place(cell.querySelector('p')!, { top: 600, width: 400, height: 300 });

        expect(kinds(cell)).toEqual(['scrolls']);
    });

    it('finds text an ellipsis cuts short', () => {
        const cell = makeCell(
            '<p class="truncate" style="overflow-x: hidden; text-overflow: ellipsis">Una descripción muy larga</p>',
        );
        const text = place(cell.querySelector('p')!, {
            width: 200,
            height: 220,
        });
        scrollSize(text, { scrollWidth: 340, clientWidth: 200 });

        expect(layoutDiagnostics(cell).diagnostics).toEqual([
            {
                kind: 'text_truncated',
                message: 'Shows 200px of 340px of text.',
                element: 'p.truncate "Una descripción muy larga"',
            },
        ]);
    });

    it('finds a chart with no size and one that draws no series', () => {
        const cell = makeCell(`
            <div class="recharts-wrapper flat"><svg class="recharts-surface"></svg></div>
            <div class="recharts-wrapper empty">
                <svg class="recharts-surface"><g class="recharts-cartesian-axis"><text>2026-08</text></g></svg>
            </div>
            <div class="recharts-wrapper drawn">
                <svg class="recharts-surface"><path class="recharts-bar-rectangle"></path></svg>
            </div>
        `);
        place(cell.querySelector('.flat')!, { width: 400, height: 0 });
        place(cell.querySelector('.empty')!, { width: 400, height: 80 });
        place(cell.querySelector('.empty svg')!, { width: 400, height: 80 });
        place(cell.querySelector('.drawn')!, { width: 400, height: 80 });
        place(cell.querySelector('.drawn svg')!, { width: 400, height: 80 });
        place(cell.querySelector('.drawn path')!, { width: 20, height: 60 });

        const { diagnostics } = layoutDiagnostics(cell);

        expect(
            diagnostics
                .filter(({ kind }) => kind.startsWith('chart'))
                .map(({ kind, element }) => [kind, element]),
        ).toEqual([
            ['chart_no_size', 'div.recharts-wrapper.flat'],
            ['chart_empty', 'div.recharts-wrapper.empty "2026-08"'],
        ]);
    });

    it('finds a ResponsiveContainer that measured no room and drew no chart', () => {
        const cell = makeCell(
            '<div class="recharts-responsive-container"><div style="width: 0px; height: 0px"></div></div>',
        );
        place(cell.querySelector('.recharts-responsive-container')!, {
            width: 400,
            height: 0,
        });

        expect(layoutDiagnostics(cell).diagnostics).toContainEqual({
            kind: 'chart_no_size',
            message:
                "A chart's container is 400×0px, so it draws nothing: a ResponsiveContainer needs a parent with a height (h-full on every ancestor up to the cell, or a fixed one).",
            element: 'div.recharts-responsive-container',
        });
    });

    it('says when a fixed cell is mostly empty, or nothing shows at all', () => {
        const sparse = makeCell('<p>Hola</p>');
        place(sparse.querySelector('p')!, { top: 0, width: 100, height: 40 });

        expect(kinds(sparse)).toEqual(['mostly_empty']);
        expect(kinds(makeCell('<div></div>'))).toEqual(['nothing_visible']);
    });

    it('does not call an `a` cell empty: it is as tall as its content', () => {
        const cell = makeCell('<p>Hola</p>', { width: 400, height: 40 });
        cell.style.height = '';
        place(cell.querySelector('p')!, { width: 100, height: 40 });

        expect(kinds(cell)).toEqual([]);
    });

    it('ignores the tooltip recharts keeps hidden in the DOM', () => {
        const cell = makeCell(
            '<p>Hola</p><div class="recharts-tooltip-wrapper"><p>€ 42</p></div>',
        );
        place(cell.querySelector('p')!, { width: 400, height: 200 });
        place(cell.querySelector('.recharts-tooltip-wrapper p')!, {
            top: 900,
            width: 100,
            height: 30,
        });

        expect(kinds(cell)).toEqual([]);
    });
});

describe('describe', () => {
    it('names an element by its tag, first classes and the start of its text', () => {
        const element = document.createElement('p');
        element.className = 'truncate text-sm font-medium text-muted';
        element.textContent = `  ${'Supermercado '.repeat(5)} `;

        expect(describeElement(element)).toBe(
            'p.truncate.text-sm.font-medium "Supermercado Supermercado Supermercado S…"',
        );
    });
});
