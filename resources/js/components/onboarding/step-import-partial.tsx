import { StepButton } from '@/components/onboarding/step-button';
import {
    StepList,
    StepRow,
    StepSectionLabel,
} from '@/components/onboarding/step-list';
import {
    StepCallout,
    StepEmphasis,
    StepScreen,
} from '@/components/onboarding/step-screen';
import { __ } from '@/utils/i18n';
import { CircleAlert } from 'lucide-react';
import { useMemo } from 'react';

/** How many row numbers are named before the rest become a count. */
const NAMED_ROWS = 3;

/** One row of the file that did not make it, and why. */
export interface ImportFailure {
    /** The row's own number in the file, so it can be found in a spreadsheet. */
    rowNumber: number;
    reason: string;
}

/** One reason, and every row that hit it. */
interface FailureGroup {
    reason: string;
    rows: number[];
}

/**
 * Where the rows are, in the words of someone about to open the file: a run of
 * neighbours is a range, and scattered ones are named until naming them stops
 * helping.
 */
export function describeRows(rows: number[]): string {
    const sorted = [...rows].sort((a, b) => a - b);

    if (sorted.length === 0) {
        return '';
    }

    if (sorted.length === 1) {
        return __('Row :row', { row: sorted[0] });
    }

    const isRun = sorted.every(
        (row, index) => index === 0 || row === sorted[index - 1] + 1,
    );

    if (isRun) {
        return __('Rows :from–:to', {
            from: sorted[0],
            to: sorted[sorted.length - 1],
        });
    }

    const named = sorted.slice(0, NAMED_ROWS).join(', ');

    return sorted.length > NAMED_ROWS
        ? __('Rows :rows and :count more', {
              rows: named,
              count: sorted.length - NAMED_ROWS,
          })
        : __('Rows :rows', { rows: named });
}

/** Rows that failed the same way are one line, not one line each. */
function groupFailures(failures: ImportFailure[]): FailureGroup[] {
    const groups = new Map<string, FailureGroup>();

    for (const failure of failures) {
        const group = groups.get(failure.reason) ?? {
            reason: failure.reason,
            rows: [],
        };
        group.rows.push(failure.rowNumber);
        groups.set(failure.reason, group);
    }

    return Array.from(groups.values()).sort(
        (a, b) => b.rows.length - a.rows.length,
    );
}

interface StepImportPartialProps {
    importedCount: number;
    failures: ImportFailure[];
    onContinue: () => void;
    onRetry: () => void;
}

/**
 * Some rows in, some not. Both halves are said at once because the user has to
 * act on both: the successes are already saved and must not be imported again,
 * and the failures are theirs to fix in the file.
 */
export function StepImportPartial({
    importedCount,
    failures,
    onContinue,
    onRetry,
}: StepImportPartialProps) {
    const groups = useMemo(() => groupFailures(failures), [failures]);

    return (
        <StepScreen
            title={__(":imported in, :failed we couldn't read", {
                imported: importedCount,
                failed: failures.length,
            })}
            description={
                importedCount > 0
                    ? __(
                          "The ones that worked are already saved. We won't import them twice if you retry.",
                      )
                    : __('Nothing was saved, so nothing is there to undo.')
            }
            footer={
                <>
                    {importedCount > 0 && (
                        <StepButton
                            text={__('Continue with :count', {
                                count: importedCount,
                            })}
                            onClick={onContinue}
                        />
                    )}
                    <StepButton
                        text={__('Fix the file and retry')}
                        variant={importedCount > 0 ? 'ghost' : 'default'}
                        onClick={onRetry}
                    />
                </>
            }
        >
            <div className="flex flex-col gap-1">
                <StepSectionLabel>{__('What went wrong')}</StepSectionLabel>
                <StepList>
                    {groups.map((group) => (
                        <StepRow
                            key={group.reason}
                            icon={CircleAlert}
                            title={
                                group.rows.length === 1
                                    ? __('1 row')
                                    : __(':count rows', {
                                          count: group.rows.length,
                                      })
                            }
                            description={group.reason}
                            meta={describeRows(group.rows)}
                        />
                    ))}
                </StepList>

                <div className="pt-5">
                    <StepCallout>
                        <StepEmphasis
                            sentence={__(
                                'Most of the time this is a header row or a totals line at the bottom of the file. :fix — only the ones that failed are added.',
                            )}
                            word={__('Fix those rows and upload it again')}
                        />
                    </StepCallout>
                </div>
            </div>
        </StepScreen>
    );
}
