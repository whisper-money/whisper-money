import { ForecastAccountsSheet } from '@/components/forecast/forecast-account-choices';
import { ForecastAccountSheet } from '@/components/forecast/forecast-account-sheet';
import {
    ForecastAddSheet,
    type AddPrefill,
} from '@/components/forecast/forecast-add-sheet';
import { ForecastEstimateSheet } from '@/components/forecast/forecast-estimate-sheet';
import { ForecastLineSheet } from '@/components/forecast/forecast-line-sheet';
import { accountSheetForecast } from '@/lib/forecast/all-accounts';
import { type ProjectionInput } from '@/lib/forecast/types';
import {
    type ForecastAllData,
    type ForecastData,
    type ForecastGroup,
    type ForecastLine,
} from '@/types/forecast';
import { createContext, useContext, useState, type ReactNode } from 'react';

export type ForecastEditTarget =
    | { kind: 'line'; line: ForecastLine; month: string }
    | {
          kind: 'estimate';
          group: ForecastGroup;
          month: string;
          /** The account of the category's estimate, out of the "All accounts" view. */
          accountId?: string;
      }
    | { kind: 'add'; prefill?: AddPrefill }
    | { kind: 'account' }
    | { kind: 'accounts' };

interface ForecastEditor {
    open: (target: ForecastEditTarget) => void;
}

const ForecastEditorContext = createContext<ForecastEditor | null>(null);

/** Opens the forecast's edit sheets; null outside the page (nothing to edit). */
export function useForecastEditor(): ForecastEditor | null {
    return useContext(ForecastEditorContext);
}

/** What every edit sheet is handed. */
export interface ForecastSheetProps {
    forecast: ForecastData;
    projectionInput: ProjectionInput;
    onClose: () => void;
}

/** The account an edit is about, when the target names one. */
function targetAccountId(target: ForecastEditTarget): string | undefined {
    switch (target.kind) {
        case 'line':
            return target.line.accountId;
        case 'estimate':
            return target.accountId;
        case 'add':
            return target.prefill?.accountId;
        default:
            return undefined;
    }
}

/**
 * Holds which edit sheet of the forecast is open (a line, a category's
 * estimate, a new line, the account's settings or the accounts covered)
 * and lets the table, the warnings and the figures open them. Out of the "All accounts" view, a
 * sheet edits the account the line, the estimate or the new line is on.
 */
export function ForecastEditorProvider({
    forecast: page,
    projectionInput,
    children,
}: {
    forecast: ForecastData | ForecastAllData;
    projectionInput: ProjectionInput | null;
    children: ReactNode;
}) {
    const [target, setTarget] = useState<ForecastEditTarget | null>(null);
    const [sequence, setSequence] = useState(0);
    const forecast =
        page.scope === 'all'
            ? accountSheetForecast(
                  page,
                  target === null ? null : targetAccountId(target),
              )
            : page;

    const editor: ForecastEditor | null =
        projectionInput === null
            ? null
            : {
                  open: (next) => {
                      setTarget(next);
                      setSequence((count) => count + 1);
                  },
              };

    const sheet: ForecastSheetProps | null =
        projectionInput === null
            ? null
            : { forecast, projectionInput, onClose: () => setTarget(null) };

    return (
        <ForecastEditorContext.Provider value={editor}>
            {children}
            {sheet && target?.kind === 'line' && (
                <ForecastLineSheet
                    key={sequence}
                    {...sheet}
                    line={target.line}
                    month={target.month}
                />
            )}
            {sheet && target?.kind === 'estimate' && (
                <ForecastEstimateSheet
                    key={sequence}
                    {...sheet}
                    group={target.group}
                    month={target.month}
                />
            )}
            {sheet && target?.kind === 'add' && (
                <ForecastAddSheet
                    key={sequence}
                    {...sheet}
                    prefill={target.prefill}
                />
            )}
            {sheet && target?.kind === 'account' && (
                <ForecastAccountSheet key={sequence} {...sheet} />
            )}
            {sheet && target?.kind === 'accounts' && (
                <ForecastAccountsSheet key={sequence} {...sheet} />
            )}
        </ForecastEditorContext.Provider>
    );
}
