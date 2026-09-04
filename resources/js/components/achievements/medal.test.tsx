import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Medal } from './medal';

/*
 * A tier is a metal, and the metal is drawn, not written: there is no text to
 * assert on, so these check the geometry that carries the ladder. Shape has to
 * escalate alongside the colour — a reader who cannot separate copper from gold
 * still has to see which medal outranks which — and every gradient has to be
 * keyed to its own medal, because a page of these shares one document.
 */

describe('Medal', () => {
    it('escalates the rim shape so the ladder survives greyscale', () => {
        const { container: common } = render(
            <Medal rarity="common" icon="piggy-bank" />,
        );
        const { container: uncommon } = render(
            <Medal rarity="uncommon" icon="piggy-bank" />,
        );
        const { container: rare } = render(
            <Medal rarity="rare" icon="piggy-bank" />,
        );

        // A plain disc, then a disc inside a ring, then a serrated crown.
        expect(common.querySelectorAll('polygon')).toHaveLength(0);
        expect(common.querySelectorAll('circle[r="22.25"]')).toHaveLength(0);
        expect(uncommon.querySelectorAll('polygon')).toHaveLength(0);
        expect(uncommon.querySelectorAll('circle[r="22.25"]')).toHaveLength(2);
        expect(rare.querySelectorAll('polygon')).toHaveLength(2);
    });

    it('gives obsidian a gold crown and an inner bezel of its own', () => {
        const { container } = render(<Medal rarity="epic" icon="landmark" />);

        // Two metals means two face ramps: the obsidian face and the gold crown.
        const ramps = [...container.querySelectorAll('linearGradient')].filter(
            (gradient) => gradient.id.endsWith('-face'),
        );
        expect(ramps).toHaveLength(2);

        // The bezel ring only obsidian wears, at face - 2.6.
        expect(container.querySelector('circle[r="15.4"]')).not.toBeNull();
    });

    it('keys every gradient to its own medal', () => {
        const { container } = render(
            <>
                <Medal rarity="rare" icon="flame" />
                <Medal rarity="rare" icon="coins" />
            </>,
        );

        const ids = [...container.querySelectorAll('[id]')].map(
            (node) => node.id,
        );
        expect(ids).toHaveLength(new Set(ids).size);

        // And nothing points at a definition that isn't there.
        const referenced = [...container.querySelectorAll('*')]
            .flatMap((node) => [
                node.getAttribute('fill'),
                node.getAttribute('stroke'),
                node.getAttribute('clip-path'),
            ])
            .filter((value): value is string =>
                Boolean(value?.startsWith('url(#')),
            )
            .map((value) => value.slice(5, -1));
        expect(referenced.length).toBeGreaterThan(0);
        expect(referenced.every((id) => ids.includes(id))).toBe(true);
    });

    it('stamps the pictogram twice, lit copy under ink', () => {
        const { container } = render(
            <Medal rarity="common" icon="piggy-bank" size={36} />,
        );

        // One svg for the medal, two for the engraving.
        const drawn = [...container.querySelectorAll('svg')];
        expect(drawn).toHaveLength(3);

        // Both copies of the glyph are centred the same way horizontally and
        // differ only down the y axis. Tailwind's -translate-x-1/2 compiles to
        // the same `translate` property we write here, so mixing the two drops
        // the half-width and slides the lit copy sideways — which shipped once.
        const [lit, ink] = drawn.slice(1).map((node) => node.style.translate);
        expect(lit.startsWith('-50%')).toBe(true);
        expect(ink).toBe('-50% -50%');
        expect(lit).not.toBe(ink);
    });

    it('draws a medal still to come as an empty dashed ring', () => {
        const { container } = render(
            <Medal rarity="epic" icon="landmark" locked />,
        );

        expect(container.querySelectorAll('svg')).toHaveLength(1);
        expect(
            container.querySelector('circle[stroke-dasharray="3 3"]'),
        ).not.toBeNull();
        expect(container.querySelectorAll('polygon')).toHaveLength(0);
    });
});
