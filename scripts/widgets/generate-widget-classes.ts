/**
 * Regenerate resources/js/widgets/widget-classes.json from
 * resources/css/widgets.css. Run it after changing the widget safelist:
 *
 *   bun scripts/widgets/generate-widget-classes.ts
 *
 * The JSON is committed: the widget validator checks classes against it and
 * get_widget_sdk hands it to agents. A frontend test fails when it drifts from
 * the stylesheet.
 */
import { writeFile } from 'node:fs/promises';
import {
    classNamesIn,
    compileWidgetCss,
    WIDGET_CLASSES_PATH,
} from './widget-css';

const classes = classNamesIn(await compileWidgetCss());

await writeFile(
    WIDGET_CLASSES_PATH,
    JSON.stringify({ version: 1, classes }, null, 4) + '\n',
);

console.log(`Wrote ${classes.length} widget classes to ${WIDGET_CLASSES_PATH}`);
