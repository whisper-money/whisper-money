// @vitest-environment node
import { describe, expect, it } from 'vitest';
import { widgetSdkReference } from '../../../scripts/widgets/sdk-reference';
import reference from './widget-sdk-reference.json';

describe('widget-sdk-reference.json', () => {
    it('lists exactly what the widget validator accepts', () => {
        expect(
            reference,
            'Run `bun scripts/widgets/generate-sdk-reference.ts` after changing the validator lists',
        ).toEqual(JSON.parse(JSON.stringify(widgetSdkReference())));
    });
});
