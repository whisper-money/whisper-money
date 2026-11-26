import { store as acceptDrift } from '@/actions/App/Http/Controllers/Forecast/ForecastItemDriftController';
import { ForecastAccountSheet } from '@/components/forecast/forecast-account-sheet';
import {
    ForecastAddSheet,
    type AddPrefill,
} from '@/components/forecast/forecast-add-sheet';
import { ForecastEstimateSheet } from '@/components/forecast/forecast-estimate-sheet';
import { ForecastLineSheet } from '@/components/forecast/forecast-line-sheet';
import { useForecastSave } from '@/hooks/use-forecast-save';
import { editedDefinitions, withLine } from '@/lib/forecast/edits';
import { type ProjectionInput } from '@/lib/forecast/types';
import {
    type ForecastData,
    type ForecastGroup,
    type ForecastLine,
} from '@/types/forecast';
import {
    createContext,
    useContext,
    useMemo,
    useState,
    type ReactNode,
} from 'react';

export type ForecastEditTarget =
    | { kind: 'line'; line: ForecastLine; month: string }
    | { kind: 'estimate'; group: ForecastGroup; month: string }
    | { kind: 'add'; prefill?: AddPrefill }
    | { kind: 'account' };

interface ForecastEditor {
    open: (target: ForecastEditTarget) => void;
    acceptDrift: (line: ForecastLine) => void;
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

/**
 * Holds which edit sheet of the forecast is open (a line, an estimated
 * rest, a new line or the account's settings) and lets the table, the
 * warnings and the figures open them.
 */
export function ForecastEditorProvider({
    forecast,
    projectionInput,
    children,
}: {
    forecast: ForecastData;
    projectionInput: ProjectionInput | null;
    children: ReactNode;
}) {
    const [target, setTarget] = useState<ForecastEditTarget | null>(null);
    const [sequence, setSequence] = useState(0);
    const { save } = useForecastSave();

    const editor = useMemo<ForecastEditor | null>(
        () =>
            projectionInput === null
                ? null
                : {
                      open: (next) => {
                          setTarget(next);
                          setSequence((count) => count + 1);
                      },
                      acceptDrift: (line) => {
                          if (line.drift === null) {
                              return;
                          }

                          const definitions = editedDefinitions(
                              line.definition,
                              {
                                  scope: 'from_here',
                                  occurrenceDate: line.drift.from,
                                  amount: line.drift.amount,
                                  frequency: null,
                                  day: null,
                              },
                              forecast.today,
                          );

                          save(
                              acceptDrift(line.id),
                              {},
                              {
                                  input: withLine(
                                      projectionInput,
                                      line.id,
                                      definitions,
                                  ),
                                  itemIds: [line.id],
                              },
                          );
                      },
                  },
        [projectionInput, forecast.today, save],
    );

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
        </ForecastEditorContext.Provider>
    );
}
