import { fetchBankMatches } from '@/lib/full-import-api';
import { sourceSuffix } from '@/lib/full-import-format';
import {
    buildImport,
    categoryNodeId,
    detectAccounts,
    detectCategories,
    importedRows,
    isOwnTransferNode,
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

    const supportedCurrencies = useMemo(
        () => currencies.accounts.map((currency) => currency.code),
        [currencies],
    );

    const normalized = useMemo(
        () =>
            parsed && mapping
                ? normalizeRows(parsed, mapping, {
                      fileName: parsed.file.name,
                      supportedCurrencies,
                  })
                : EMPTY_FILE,
        [parsed, mapping, supportedCurrencies],
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

    const rowsToImport = useMemo(
        () => importedRows(normalized.rows, accountPlan),
        [normalized, accountPlan],
    );
    const categorizedRows = useMemo(
        () => rowsToImport.filter((row) => !row.ignored),
        [rowsToImport],
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
            own: categorizedRows.filter(
                (row) =>
                    row.categoryPath.length > 0 &&
                    ownIds.has(categoryNodeId(row.categoryPath)),
            ).length,
            ignored: rowsToImport.length - categorizedRows.length,
            uncategorized: categorizedRows.filter(
                (row) => row.categoryPath.length === 0,
            ).length,
            skippedAccounts: normalized.rows.length - rowsToImport.length,
        };
    }, [nodes, categorizedRows, rowsToImport, normalized]);

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

    const built = useMemo(
        () =>
            withPayload && mapping && transfers && context
                ? buildImport({
                      source,
                      fileName: parsed?.file.name ?? null,
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
                  })
                : null,
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

    // The bank behind each account name is a server question; asked once per
    // name, when the accounts step first needs it.
    useEffect(() => {
        if (!lookUpBanks) {
            return;
        }

        const missing = fileAccounts
            .map((account) => account.name)
            .filter((name) => !(name in banks));

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
        fileAccounts,
        accountPlan,
        nodes,
        categoryPlan,
        counts,
        transfers,
        built,
        banksVersion: String(Object.keys(banks).length),
        setAccount,
        setCategory,
        setOwnChoice,
        setIgnoredChoice,
        reset,
    };
}
