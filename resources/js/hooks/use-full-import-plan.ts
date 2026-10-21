import { fetchBankMatches } from '@/lib/full-import-api';
import { importFileName } from '@/lib/full-import-files';
import { sourceSuffix } from '@/lib/full-import-format';
import {
    collectCurrencySymbols,
    moneyProReader,
} from '@/lib/full-import-money-pro';
import {
    bankLookupName,
    buildImport,
    categoryNodeId,
    detectAccounts,
    detectCategories,
    existingTargets,
    importedRows,
    isOwnTransferNode,
    movementRows,
    resolveAccountPlan,
    resolveCategoryPlan,
} from '@/lib/full-import-plan';
import { normalizeRows } from '@/lib/full-import-profiles';
import { type ParsedImportFile } from '@/lib/transaction-import';
import { type SharedData } from '@/types';
import {
    type AccountPlanEntry,
    type BankLite,
    type CategoryPlanEntry,
    type FullImportContext,
    type FullImportMapping,
    type FullImportMode,
    type FullImportSource,
    type NormalizedFile,
} from '@/types/full-import';
import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';

const EMPTY_FILE: NormalizedFile = { rows: [], unreadable: [], blankRows: 0 };

/**
 * Run a step of the plan, keeping a throw as a value: a bug in reading an
 * odd file should leave the wizard standing (and be reported), not take the
 * screen down with it.
 */
function attempt<T>(run: () => T, fallback: T): { value: T; error: unknown } {
    try {
        return { value: run(), error: null };
    } catch (error) {
        return { value: fallback, error };
    }
}

interface FullImportPlanInput {
    parsed: ParsedImportFile | null;
    mapping: FullImportMapping | null;
    context: FullImportContext | null;
    source: FullImportSource;
    mode: FullImportMode;
    /** Ask the server which bank each account belongs to (the accounts step). */
    lookUpBanks: boolean;
    /** Build the payload too, which only the review needs. */
    withPayload: boolean;
}

/**
 * Everything the wizard's screens show about the plan, derived from the file,
 * the mapping and the user's choices. Only the choices are state: changing a
 * column re-reads the rows, and the accounts, categories and payload follow
 * without anything going stale.
 */
export function useFullImportPlan({
    parsed,
    mapping,
    context,
    source,
    mode,
    lookUpBanks,
    withPayload,
}: FullImportPlanInput) {
    const { auth, currencies } = usePage<SharedData>().props;
    const userCurrency = auth.user.currency_code;

    const [accountOverrides, setAccountOverrides] = useState<
        Record<string, AccountPlanEntry>
    >({});
    const [banks, setBanks] = useState<Record<string, BankLite | null>>({});
    const [categoryOverrides, setCategoryOverrides] = useState<
        Record<string, Partial<CategoryPlanEntry>>
    >({});
    const [ownChoice, setOwnChoice] = useState<string | null | undefined>();
    const [ignoredChoice, setIgnoredChoice] = useState<
        string | null | undefined
    >();

    // Keyed on the codes rather than the prop object, so a props object
    // rebuilt with the same currencies does not re-read the whole file.
    const currencyCodes = currencies.accounts
        .map((currency) => currency.code)
        .join(',');
    const supportedCurrencies = useMemo(
        () => currencyCodes.split(','),
        [currencyCodes],
    );

    // Money Pro's rows carry their sign, transfers and balances in columns
    // the mapping has no field for, so they get a reader of their own.
    const readRow = useMemo(
        () =>
            source === 'money_pro' && parsed
                ? moneyProReader(parsed.headers)
                : undefined,
        [source, parsed],
    );

    const reading = useMemo(
        () =>
            attempt(
                () =>
                    parsed && mapping
                        ? normalizeRows(
                              parsed,
                              mapping,
                              {
                                  fileName: parsed.file.name,
                                  supportedCurrencies,
                                  userCurrency,
                              },
                              readRow,
                          )
                        : EMPTY_FILE,
                EMPTY_FILE,
            ),
        [parsed, mapping, supportedCurrencies, userCurrency, readRow],
    );
    const normalized = reading.value;

    // What each currency symbol of a Money Pro file is read as, for the user
    // to confirm: the file writes "$" for dollars and pesos alike.
    const currencySymbols = useMemo(
        () =>
            source === 'money_pro' && parsed && mapping
                ? collectCurrencySymbols(parsed.rows, parsed.headers, mapping, {
                      supportedCurrencies,
                      userCurrency,
                  })
                : [],
        [source, parsed, mapping, supportedCurrencies, userCurrency],
    );

    const fileAccounts = useMemo(
        () => detectAccounts(normalized.rows),
        [normalized],
    );

    const accountPlan = useMemo(
        () =>
            resolveAccountPlan(fileAccounts, accountOverrides, {
                mode,
                accounts: context?.accounts ?? [],
                mappableAccountIds: context?.mappableAccountIds ?? [],
                userCurrency,
                supportedCurrencies,
                sourceLabel: sourceSuffix(source),
                banks,
            }),
        [
            fileAccounts,
            accountOverrides,
            mode,
            context,
            userCurrency,
            supportedCurrencies,
            source,
            banks,
        ],
    );

    const targets = useMemo(
        () => existingTargets(fileAccounts, accountPlan),
        [fileAccounts, accountPlan],
    );

    const rowsToImport = useMemo(
        () => importedRows(normalized.rows, accountPlan),
        [normalized, accountPlan],
    );
    const movements = useMemo(() => movementRows(rowsToImport), [rowsToImport]);
    const categorizedRows = useMemo(
        () =>
            movements.filter((row) => !row.ignored && row.kind === 'movement'),
        [movements],
    );
    const nodes = useMemo(
        () => detectCategories(categorizedRows),
        [categorizedRows],
    );
    const categoryPlan = useMemo(
        () =>
            resolveCategoryPlan(nodes, categoryOverrides, {
                categories: context?.categories ?? [],
                defaultNames: context?.defaultCategoryNames ?? [],
            }),
        [nodes, categoryOverrides, context],
    );

    const counts = useMemo(() => {
        const ownIds = new Set(
            nodes
                .filter((node) => isOwnTransferNode(node, nodes))
                .map((node) => node.id),
        );

        return {
            own:
                categorizedRows.filter(
                    (row) =>
                        row.categoryPath.length > 0 &&
                        ownIds.has(categoryNodeId(row.categoryPath)),
                ).length +
                movements.filter(
                    (row) => !row.ignored && row.kind === 'transfer',
                ).length,
            ignored: movements.filter((row) => row.ignored).length,
            uncategorized: categorizedRows.filter(
                (row) => row.categoryPath.length === 0,
            ).length,
            skippedAccounts:
                movementRows(normalized.rows).length - movements.length,
        };
    }, [nodes, categorizedRows, movements, normalized]);

    const transfers = useMemo(() => {
        const targets = context?.transferTargets;

        return targets
            ? {
                  own: {
                      categoryId:
                          ownChoice === undefined
                              ? targets.own.category_id
                              : ownChoice,
                      target: targets.own,
                  },
                  ignored: {
                      categoryId:
                          ignoredChoice === undefined
                              ? targets.ignored.category_id
                              : ignoredChoice,
                      target: targets.ignored,
                  },
              }
            : null;
    }, [context, ownChoice, ignoredChoice]);

    const building = useMemo(
        () =>
            withPayload && mapping && transfers && context
                ? attempt(
                      () =>
                          buildImport({
                              source,
                              fileName: parsed ? importFileName(parsed) : null,
                              mode,
                              mapping,
                              rows: normalized.rows,
                              fileAccounts,
                              accountPlan,
                              contextAccounts: context.accounts,
                              nodes,
                              categoryPlan,
                              categories: context.categories,
                              transfers,
                          }),
                      null,
                  )
                : { value: null, error: null },
        [
            withPayload,
            mapping,
            transfers,
            context,
            source,
            parsed,
            mode,
            normalized,
            fileAccounts,
            accountPlan,
            nodes,
            categoryPlan,
        ],
    );
    const built = building.value;

    // The bank behind each account name is a server question; asked once per
    // name, when the accounts step first needs it.
    useEffect(() => {
        if (!lookUpBanks) {
            return;
        }

        const missing = [...new Set(fileAccounts.map(bankLookupName))].filter(
            (name) => !(name in banks),
        );

        if (missing.length === 0) {
            return;
        }

        let active = true;

        fetchBankMatches(missing)
            .catch(() => ({}))
            .then((matches: Record<string, BankLite | null>) => {
                if (active) {
                    setBanks((previous) => ({
                        ...previous,
                        ...Object.fromEntries(
                            missing.map((name) => [
                                name,
                                matches[name] ?? null,
                            ]),
                        ),
                    }));
                }
            });

        return () => {
            active = false;
        };
    }, [lookUpBanks, fileAccounts, banks]);

    const setAccount = useCallback(
        (key: string, entry: AccountPlanEntry) =>
            setAccountOverrides((previous) => ({ ...previous, [key]: entry })),
        [],
    );

    const setCategory = useCallback(
        (nodeId: string, entry: Partial<CategoryPlanEntry>) =>
            setCategoryOverrides((previous) => ({
                ...previous,
                [nodeId]: { ...previous[nodeId], ...entry },
            })),
        [],
    );

    /** Forget every choice: a new file or a new layout starts the plan over. */
    const reset = useCallback(() => {
        setAccountOverrides({});
        setCategoryOverrides({});
        setOwnChoice(undefined);
        setIgnoredChoice(undefined);
    }, []);

    return {
        normalized,
        currencySymbols,
        fileAccounts,
        accountPlan,
        existingTargets: targets,
        nodes,
        categoryPlan,
        counts,
        transfers,
        built,
        banksVersion: String(Object.keys(banks).length),
        /** A bug in reading the rows or building the payload, for reporting. */
        planError: reading.error ?? building.error,
        setAccount,
        setCategory,
        setOwnChoice,
        setIgnoredChoice,
        reset,
    };
}
