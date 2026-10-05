import { countLabel, formatCount } from '@/lib/full-import-format';
import { type UnreadableRow } from '@/types/full-import';
import { __ } from '@/utils/i18n';

/** Enough to spot a pattern (one bad column, one bad block) without a wall. */
const LISTED_ROWS = 20;

/**
 * "3 rows can't be read", opening onto which ones and why, by their row
 * number in the file so they can be found in a spreadsheet.
 */
export function UnreadableRows({
    rows,
    locale,
}: {
    rows: UnreadableRow[];
    locale: string;
}) {
    if (rows.length === 0) {
        return null;
    }

    const rest = rows.length - LISTED_ROWS;

    return (
        <details className="text-[13px]">
            <summary className="w-fit cursor-pointer font-medium text-amber-700 dark:text-amber-400">
                {countLabel(
                    rows.length,
                    __("1 row can't be read"),
                    __(":count rows can't be read", {
                        count: formatCount(rows.length, locale),
                    }),
                )}
            </summary>
            <ul className="mt-2 flex flex-col gap-1 text-muted-foreground">
                {rows.slice(0, LISTED_ROWS).map((row) => (
                    <li key={row.rowNumber}>
                        <span className="font-medium text-foreground tabular-nums">
                            {__('Row :number', { number: row.rowNumber })}
                        </span>
                        {' · '}
                        {row.reason}
                    </li>
                ))}
            </ul>
            {rest > 0 && (
                <p className="mt-1 text-muted-foreground">
                    {countLabel(
                        rest,
                        __('And 1 more.'),
                        __('And :count more.', {
                            count: formatCount(rest, locale),
                        }),
                    )}
                </p>
            )}
        </details>
    );
}
