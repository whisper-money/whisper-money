import { describe, expect, it } from 'vitest';
import { compactWidgetHtml } from './compact-html';

function cell(html: string): HTMLElement {
    const element = document.createElement('div');
    element.className = 'wm-widget';
    element.innerHTML = html;

    return element;
}

describe('compactWidgetHtml', () => {
    it('keeps structure, classes and text and drops what only a browser needs, empty layers included', () => {
        const { html, truncated } = compactWidgetHtml(
            cell(`
                <style>.x { color: red }</style>
                <script>alert(1)</script>
                <!-- a comment -->
                <h3 class="text-sm">Supermercado</h3>
                <img src="data:image/png;base64,${'A'.repeat(500)}" alt="logo">
                <svg xmlns="http://www.w3.org/2000/svg" class="recharts-surface">
                    <title></title>
                    <defs><clipPath id="clip"><rect></rect></clipPath></defs>
                    <g class="recharts-zIndex-layer_100"><g class="empty"></g></g>
                    <path class="recharts-bar-rectangle" d="M${'1 2 L'.repeat(40)}Z"></path>
                    <path d="M0 0Z"></path>
                    <text class="recharts-cartesian-axis-tick-value">2026-08</text>
                </svg>
            `),
            10_000,
        );

        expect(truncated).toBe(false);
        expect(html).toBe(
            '<div class="wm-widget"><h3 class="text-sm">Supermercado</h3>' +
                '<img src="data:…" alt="logo">' +
                '<svg class="recharts-surface"><path class="recharts-bar-rectangle" d="…202 chars"></path>' +
                '<path d="M0 0Z"></path>' +
                '<text class="recharts-cartesian-axis-tick-value">2026-08</text></svg></div>',
        );
    });

    it('cuts a long run of same-looking siblings, more of them when they carry text', () => {
        const bars = Array.from(
            { length: 10 },
            () => '<rect class="bar"></rect>',
        ).join('');
        const ticks = Array.from(
            { length: 30 },
            (_, index) => `<span class="tick">${index}</span>`,
        ).join('');

        const { html } = compactWidgetHtml(cell(bars + ticks), 10_000);

        expect(html.match(/<rect/g)).toHaveLength(4);
        expect(html).toContain('<!-- 6 more rect.bar -->');
        expect(html.match(/<span/g)).toHaveLength(24);
        expect(html).toContain('<!-- 6 more span.tick -->');
    });

    it('stops at a tag boundary past the limit and says how much is left', () => {
        const { html, truncated } = compactWidgetHtml(
            cell(
                Array.from(
                    { length: 20 },
                    (_, index) => `<p class="row-${index}">Fila ${index}</p>`,
                ).join(''),
            ),
            120,
        );

        expect(truncated).toBe(true);
        expect(html).toMatch(/"><!-- cut: \d+ more characters -->$/);
        expect(html.length).toBeLessThan(170);
    });

    it('leaves the drawn page alone', () => {
        const root = cell('<style>.x{}</style><p>Hola</p>');

        compactWidgetHtml(root, 10_000);

        expect(root.querySelector('style')).not.toBeNull();
    });
});
