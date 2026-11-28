/**
 * What a drawn widget gets wrong that its markup does not show, measured in
 * the browser that drew it: text cut short, content the cell clips, charts
 * with no size or no series, a cell left mostly empty. render_widget answers
 * with them (scripts/render-widget.mjs) so the agent can fix the layout.
 */

export type LayoutDiagnosticKind =
    | 'cell_overflow'
    | 'clipped_by_cell'
    | 'text_truncated'
    | 'scrolls'
    | 'chart_no_size'
    | 'chart_empty'
    | 'mostly_empty'
    | 'nothing_visible';

export interface LayoutDiagnostic {
    kind: LayoutDiagnosticKind;
    message: string;
    /** The element it is about: tag, first classes and the start of its text. */
    element?: string;
}

export interface LayoutReport {
    diagnostics: LayoutDiagnostic[];
    /** How tall what the widget drew is, from the cell's top. */
    content_height: number;
    cell_height: number;
    cell_width: number;
}

/** Subpixel layout rounds both ways: under this is not a finding. */
const TOLERANCE_PX = 1;

const MAX_PER_KIND = 5;

/** Under this share of a fixed-height cell, the widget leaves it mostly empty. */
const MIN_FILL = 0.4;

/**
 * What recharts draws data with; axes, grids, legends and the hover cursor
 * are not a series.
 */
const CHART_SERIES =
    '.recharts-bar-rectangle, .recharts-line-curve, .recharts-area-area, .recharts-area-curve, .recharts-pie-sector, .recharts-radial-bar-sector, .recharts-scatter-symbol, .recharts-funnel-trapezoid, .recharts-treemap-depth-1';

/** Recharts keeps its tooltip in the DOM, hidden until hovered. */
const IGNORED = '.recharts-tooltip-wrapper, [aria-hidden="true"] *';

export function layoutDiagnostics(cell: HTMLElement): LayoutReport {
    const found: LayoutDiagnostic[] = [];
    const cellBox = cell.getBoundingClientRect();
    const fixedHeight = cell.style.height !== '';

    cellOverflow(cell, found);
    clippedByCell(cell, cellBox, found);
    truncatedText(cell, found);
    scrollContainers(cell, found);
    charts(cell, found);

    const contentHeight = contentBottom(cell) - cellBox.top;

    if (contentHeight <= 0) {
        found.push({
            kind: 'nothing_visible',
            message:
                'The widget drew no visible text or graphics: check its data and that it does not return null.',
        });
    } else if (fixedHeight && contentHeight < cellBox.height * MIN_FILL) {
        found.push({
            kind: 'mostly_empty',
            message: `The content ends ${Math.round(contentHeight)}px down a ${Math.round(cellBox.height)}px tall cell: grow it to fill the cell (h-full, flex-1) or use a smaller height.`,
        });
    }

    return {
        diagnostics: capped(found),
        content_height: Math.max(0, Math.round(contentHeight)),
        cell_height: Math.round(cellBox.height),
        cell_width: Math.round(cellBox.width),
    };
}

/** A few of each kind: the agent needs the pattern, not every instance. */
function capped(found: LayoutDiagnostic[]): LayoutDiagnostic[] {
    const counts = new Map<LayoutDiagnosticKind, number>();

    return found.filter(({ kind }) => {
        counts.set(kind, (counts.get(kind) ?? 0) + 1);

        return counts.get(kind)! <= MAX_PER_KIND;
    });
}

function cellOverflow(cell: HTMLElement, found: LayoutDiagnostic[]): void {
    const hiddenBelow = cell.scrollHeight - cell.clientHeight;
    const hiddenRight = cell.scrollWidth - cell.clientWidth;

    if (hiddenBelow > TOLERANCE_PX) {
        found.push({
            kind: 'cell_overflow',
            message: `The content is ${cell.scrollHeight}px tall but the cell shows ${cell.clientHeight}px: the bottom ${hiddenBelow}px is cut off.`,
        });
    }

    if (hiddenRight > TOLERANCE_PX) {
        found.push({
            kind: 'cell_overflow',
            message: `The content is ${cell.scrollWidth}px wide but the cell shows ${cell.clientWidth}px: the right ${hiddenRight}px is cut off.`,
        });
    }
}

/**
 * The outermost elements that reach past the cell's edges: the cell clips
 * them. What sits in a scroll container of its own scrolls instead.
 */
function clippedByCell(
    cell: HTMLElement,
    cellBox: DOMRect,
    found: LayoutDiagnostic[],
): void {
    const reported: Element[] = [];

    for (const element of cell.querySelectorAll('*')) {
        if (
            reported.some((outer) => outer.contains(element)) ||
            isIgnored(element) ||
            scrollsInside(element, cell)
        ) {
            continue;
        }

        const box = element.getBoundingClientRect();

        if (
            box.width === 0 ||
            box.height === 0 ||
            !hasVisibleContent(element)
        ) {
            continue;
        }

        const past = [
            ['bottom', box.bottom - cellBox.bottom],
            ['right', box.right - cellBox.right],
            ['top', cellBox.top - box.top],
            ['left', cellBox.left - box.left],
        ] as const;
        const edge = past.find(([, distance]) => distance > TOLERANCE_PX);

        if (edge) {
            reported.push(element);
            found.push({
                kind: 'clipped_by_cell',
                message: `Reaches ${Math.round(edge[1])}px past the cell's ${edge[0]} edge and is cut off.`,
                element: describe(element),
            });
        }
    }
}

/** Text an ellipsis, a line clamp or a hidden overflow cuts short. */
function truncatedText(cell: HTMLElement, found: LayoutDiagnostic[]): void {
    for (const element of cell.querySelectorAll<HTMLElement>('*')) {
        if (!ownText(element) || isIgnored(element)) {
            continue;
        }

        const style = getComputedStyle(element);
        const clipsSideways =
            style.textOverflow === 'ellipsis' ||
            ['hidden', 'clip'].includes(style.overflowX);
        const clamped =
            style.getPropertyValue('-webkit-line-clamp') !== '' &&
            style.getPropertyValue('-webkit-line-clamp') !== 'none';

        if (
            clipsSideways &&
            element.scrollWidth - element.clientWidth > TOLERANCE_PX
        ) {
            found.push({
                kind: 'text_truncated',
                message: `Shows ${element.clientWidth}px of ${element.scrollWidth}px of text.`,
                element: describe(element),
            });
        } else if (
            clamped &&
            element.scrollHeight - element.clientHeight > TOLERANCE_PX
        ) {
            found.push({
                kind: 'text_truncated',
                message: `A line clamp hides ${element.scrollHeight - element.clientHeight}px of text.`,
                element: describe(element),
            });
        }
    }
}

/** Not a fault, but the agent should know part of it is out of sight. */
function scrollContainers(cell: HTMLElement, found: LayoutDiagnostic[]): void {
    for (const element of cell.querySelectorAll<HTMLElement>('*')) {
        if (!isScrollContainer(element)) {
            continue;
        }

        if (element.scrollHeight - element.clientHeight > TOLERANCE_PX) {
            found.push({
                kind: 'scrolls',
                message: `Scrolls: shows ${element.clientHeight}px of its ${element.scrollHeight}px of content.`,
                element: describe(element),
            });
        }
    }
}

const NO_SIZE_MESSAGE =
    'so it draws nothing: a ResponsiveContainer needs a parent with a height (h-full on every ancestor up to the cell, or a fixed one).';

function charts(cell: HTMLElement, found: LayoutDiagnostic[]): void {
    // A ResponsiveContainer that measured no room never draws its chart.
    for (const container of cell.querySelectorAll(
        '.recharts-responsive-container',
    )) {
        if (container.querySelector('.recharts-wrapper') === null) {
            const box = container.getBoundingClientRect();

            found.push({
                kind: 'chart_no_size',
                message: `A chart's container is ${Math.round(box.width)}×${Math.round(box.height)}px, ${NO_SIZE_MESSAGE}`,
                element: describe(container),
            });
        }
    }

    for (const chart of cell.querySelectorAll('.recharts-wrapper')) {
        const box = chart.getBoundingClientRect();

        if (box.width < TOLERANCE_PX || box.height < TOLERANCE_PX) {
            found.push({
                kind: 'chart_no_size',
                message: `A chart is ${Math.round(box.width)}×${Math.round(box.height)}px, ${NO_SIZE_MESSAGE}`,
                element: describe(chart),
            });

            continue;
        }

        const drawn = [...chart.querySelectorAll(CHART_SERIES)].some(
            (shape) => {
                const shapeBox = shape.getBoundingClientRect();

                return shapeBox.width > 0 || shapeBox.height > 0;
            },
        );

        if (!drawn) {
            found.push({
                kind: 'chart_empty',
                message:
                    'A chart draws no series: its data is empty or its dataKey does not match a field of the data.',
                element: describe(chart),
            });
        }
    }
}

/** How far down the last visible text or graphic ends. */
function contentBottom(cell: HTMLElement): number {
    let bottom = Number.NEGATIVE_INFINITY;
    const range = cell.ownerDocument.createRange();
    const walker = cell.ownerDocument.createTreeWalker(
        cell,
        NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT,
    );

    for (
        let node: Node | null = walker.nextNode();
        node;
        node = walker.nextNode()
    ) {
        let box: DOMRect | null = null;

        if (node.nodeType === Node.TEXT_NODE) {
            if (node.textContent?.trim() && !isIgnored(node.parentElement)) {
                range.selectNodeContents(node);
                box = range.getBoundingClientRect();
            }
        } else if (isGraphic(node as Element) && !isIgnored(node as Element)) {
            box = (node as Element).getBoundingClientRect();
        }

        if (box && box.width > 0 && box.height > 0) {
            bottom = Math.max(bottom, box.bottom);
        }
    }

    return bottom;
}

function isGraphic(element: Element): boolean {
    return ['svg', 'img', 'canvas', 'video'].includes(
        element.tagName.toLowerCase(),
    );
}

function hasVisibleContent(element: Element): boolean {
    return (
        isGraphic(element) ||
        element.querySelector('svg, img, canvas, video') !== null ||
        (element.textContent?.trim() ?? '') !== ''
    );
}

function isIgnored(element: Element | null): boolean {
    if (element === null) {
        return true;
    }

    const style = getComputedStyle(element);

    return (
        element.matches(IGNORED) ||
        element.closest('.recharts-tooltip-wrapper') !== null ||
        style.visibility === 'hidden' ||
        style.display === 'none'
    );
}

function isScrollContainer(element: Element): boolean {
    const style = getComputedStyle(element);

    return (
        ['auto', 'scroll'].includes(style.overflowY) ||
        ['auto', 'scroll'].includes(style.overflowX)
    );
}

/** Inside a scroll container below the cell, so out of sight by design. */
function scrollsInside(element: Element, cell: HTMLElement): boolean {
    for (
        let ancestor = element.parentElement;
        ancestor !== null && ancestor !== cell;
        ancestor = ancestor.parentElement
    ) {
        if (isScrollContainer(ancestor)) {
            return true;
        }
    }

    return false;
}

function ownText(element: Element): string {
    return [...element.childNodes]
        .filter((node) => node.nodeType === Node.TEXT_NODE)
        .map((node) => node.textContent ?? '')
        .join('')
        .trim();
}

/** `p.truncate.text-sm "Supermercado y…"`: enough to find it in the html. */
export function describe(element: Element): string {
    const classes = (element.getAttribute('class') ?? '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 3);
    const text = (element.textContent ?? '').trim().replace(/\s+/g, ' ');

    return [
        [element.tagName.toLowerCase(), ...classes].join('.'),
        text === ''
            ? ''
            : `"${text.length > 40 ? `${text.slice(0, 40)}…` : text}"`,
    ]
        .filter(Boolean)
        .join(' ');
}
