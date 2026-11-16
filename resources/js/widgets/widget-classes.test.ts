// @vitest-environment node
import { describe, expect, it } from 'vitest';
import {
    classNamesIn,
    compileWidgetCss,
} from '../../../scripts/widgets/widget-css';
import widgetClasses from './widget-classes.json';

describe('widget-classes.json', () => {
    it('lists exactly the classes resources/css/widgets.css defines', async () => {
        const css = await compileWidgetCss();

        expect(
            widgetClasses.classes,
            'Run `bun scripts/widgets/generate-widget-classes.ts` after changing widgets.css',
        ).toEqual(classNamesIn(css));
    });

    it('scopes every utility to the widget container and ships no preflight', async () => {
        const css = await compileWidgetCss();

        expect(css).not.toContain('@layer base');
        expect(css).toMatch(
            /^\.wm-widget \{\n {2}container-type: inline-size;/m,
        );
        expect(widgetClasses.classes).toEqual(
            expect.arrayContaining([
                'p-4',
                'dark:bg-red-950',
                '@sm:grid-cols-2',
                'bg-muted/10',
                'text-muted-foreground',
            ]),
        );
        expect(widgetClasses.classes.some((name) => name.includes('['))).toBe(
            false,
        );
    });
});
