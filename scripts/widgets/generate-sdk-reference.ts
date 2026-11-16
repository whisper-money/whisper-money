/**
 * Regenerate resources/js/widgets/widget-sdk-reference.json from the widget
 * validator's closed lists. Run it after changing them:
 *
 *   bun scripts/widgets/generate-sdk-reference.ts
 *
 * The JSON is committed (format it with `bun run format`): get_widget_sdk
 * hands it to agents. A frontend test
 * fails when it drifts from the validator.
 */
import { writeFile } from 'node:fs/promises';
import { WIDGET_SDK_REFERENCE_PATH, widgetSdkReference } from './sdk-reference';

await writeFile(
    WIDGET_SDK_REFERENCE_PATH,
    JSON.stringify(widgetSdkReference(), null, 4) + '\n',
);

console.log(`Wrote the widget SDK reference to ${WIDGET_SDK_REFERENCE_PATH}`);
