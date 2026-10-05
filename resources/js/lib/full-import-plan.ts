import { normalizeText, toSavedProfile } from '@/lib/full-import-profiles';
import {
    type Category,
    type CategoryColor,
    type CategoryType,
} from '@/types/category';
import {
    type AccountPayloadEntry,
    type AccountPlanEntry,
    type BalancePayloadRow,
    type BankLite,
    type CategoryMatchKind,
    type CategoryPayloadEntry,
    type CategoryPlanEntry,
    type ContextAccount,
    type FileAccount,
    type FileCategoryNode,
    type FullImportMapping,
    type FullImportMode,
    type FullImportSource,
    type ImportAccountType,
    type ImportPayload,
    type NormalizedRow,
    type TransactionPayloadRow,
    type TransferTarget,
} from '@/types/full-import';
import { toMinorUnits } from '@/utils/currency';

/** The plan keys the two kinds of transfer use, whatever the file calls them. */
export const OWN_TRANSFER_KEY = 'own';
export const IGNORED_KEY = 'ignored';

/** How deep the category tree goes, a root being level 1. */
const MAX_CATEGORY_DEPTH = 3;

/** Every name another app gives the transfers between a user's own accounts. */
const OWN_TRANSFER_NAMES = new Set([
    'traspasos propios',
    'traspaso propio',
    'transferencias propias',
    'transferencia propia',
    'own transfers',
    'own transfer',
]);

/* ------------------------------------------------------------------------ */
/* Accounts                                                                  */
/* ------------------------------------------------------------------------ */

function mostCommon(values: (string | null)[]): string | null {
    const tally = new Map<string, number>();

    for (const value of values) {
        if (value) {
            tally.set(value, (tally.get(value) ?? 0) + 1);
        }
    }

    let best: string | null = null;

    for (const [value, count] of tally) {
        if (best === null || count > (tally.get(best) ?? 0)) {
            best = value;
        }
    }

    return best;
}

/** The accounts the file's rows point at, the busiest first. */
export function detectAccounts(rows: NormalizedRow[]): FileAccount[] {
    const groups = new Map<string, NormalizedRow[]>();

    for (const row of rows) {
        groups.set(row.accountKey, [
            ...(groups.get(row.accountKey) ?? []),
            row,
        ]);
    }

    return [...groups.entries()]
        .map(([key, accountRows]) => {
            const dates = accountRows.map((row) => row.date).sort();
            const balances = latestBalances(accountRows);
            const lastDate = [...balances.keys()].sort().at(-1) ?? null;

            return {
                key,
                name: accountRows[0].accountName,
                count: accountRows.length,
                from: dates[0],
                to: dates[dates.length - 1],
                currency: mostCommon(accountRows.map((row) => row.currency)),
                balanceCount: balances.size,
                lastBalance:
                    lastDate === null
                        ? null
                        : {
                              date: lastDate,
                              amount: balances.get(lastDate) as number,
                          },
                iban: accountRows.find((row) => row.iban)?.iban ?? null,
            };
        })
        .sort((a, b) => b.count - a.count);
}

const CASH_NAME = /\b(cash|efectivo|metalico)\b/;

/** Cash is an account without a bank. */
export function isCashAccountName(name: string): boolean {
    return CASH_NAME.test(normalizeText(name));
}

/** A first guess at the account type from the name the user gave it. */
export function guessAccountType(name: string): ImportAccountType {
    const normalized = normalizeText(name);

    if (/\b(ahorro|ahorros|saving|savings)\b/.test(normalized)) {
        return 'savings';
    }

    if (
        /\b(tarjeta|card|credit|credito|amex|visa|mastercard)\b/.test(
            normalized,
        )
    ) {
        return 'credit_card';
    }

    return isCashAccountName(name) ? 'others' : 'checking';
}

/** One of the user's accounts carrying the same name, case and accents aside. */
export function namesakeAccount(
    account: FileAccount,
    accounts: ContextAccount[],
    filter: (candidate: ContextAccount) => boolean,
): ContextAccount | null {
    const name = normalizeText(account.name);

    return (
        accounts.find(
            (candidate) =>
                filter(candidate) && normalizeText(candidate.name) === name,
        ) ?? null
    );
}

export interface AccountPlanContext {
    mode: FullImportMode;
    accounts: ContextAccount[];
    mappableAccountIds: string[];
    userCurrency: string;
    supportedCurrencies: readonly string[];
    /** Appended to a file account that clashes with a connected one. */
    sourceLabel: string;
    banks: Record<string, BankLite | null>;
}

/** The connected account a file account would have gone into, if any. */
export function connectedNamesake(
    account: FileAccount,
    context: Pick<AccountPlanContext, 'accounts'>,
): ContextAccount | null {
    return namesakeAccount(
        account,
        context.accounts,
        (candidate) => candidate.connected,
    );
}

/**
 * What the import does with a file account unless the user says otherwise:
 * into a manual account of the same name when the space has one, a new
 * account otherwise. A connected namesake never receives imported rows, so
 * its history goes to a separate manual account named after the source.
 */
export function defaultAccountPlan(
    account: FileAccount,
    context: AccountPlanContext,
): AccountPlanEntry {
    const mappable = new Set(context.mappableAccountIds);
    const mapTarget =
        context.mode === 'add'
            ? namesakeAccount(account, context.accounts, (candidate) =>
                  mappable.has(candidate.id),
              )
            : null;
    const connected = connectedNamesake(account, context);
    const currency =
        account.currency &&
        context.supportedCurrencies.includes(account.currency)
            ? account.currency
            : context.userCurrency;

    return {
        action: mapTarget ? 'map' : 'create',
        name: connected
            ? `${account.name} (${context.sourceLabel})`
            : account.name,
        type: guessAccountType(account.name),
        currencyCode: currency,
        bank: isCashAccountName(account.name)
            ? null
            : (context.banks[account.name] ?? null),
        targetAccountId: mapTarget?.id ?? null,
        mergeIntoKey: null,
    };
}

/**
 * The plan the import will run with: the user's choices over the defaults,
 * with anything the server would refuse turned back into a new account — a
 * mapping onto an account that may not receive rows, or a merge into an
 * account that is not imported itself.
 */
export function resolveAccountPlan(
    accounts: FileAccount[],
    overrides: Record<string, AccountPlanEntry>,
    context: AccountPlanContext,
): Record<string, AccountPlanEntry> {
    const mappable = new Set(context.mappableAccountIds);
    const plan: Record<string, AccountPlanEntry> = {};

    for (const account of accounts) {
        const entry =
            overrides[account.key] ?? defaultAccountPlan(account, context);
        const badMap =
            entry.action === 'map' &&
            (context.mode === 'wipe' ||
                !entry.targetAccountId ||
                !mappable.has(entry.targetAccountId));

        plan[account.key] = badMap
            ? { ...defaultAccountPlan(account, context), action: 'create' }
            : entry;
    }

    for (const account of accounts) {
        const entry = plan[account.key];
        const into = entry.mergeIntoKey ? plan[entry.mergeIntoKey] : undefined;

        if (
            entry.action === 'merge' &&
            (!into ||
                entry.mergeIntoKey === account.key ||
                (into.action !== 'create' && into.action !== 'map'))
        ) {
            plan[account.key] = { ...entry, action: 'create' };
        }
    }

    return plan;
}

/**
 * The currency a file account's movements land in: its own when created,
 * the target's when mapped or merged. Null when it is not imported.
 */
export function targetCurrency(
    key: string,
    plan: Record<string, AccountPlanEntry>,
    accounts: ContextAccount[],
): string | null {
    const entry = plan[key];

    if (!entry || entry.action === 'skip') {
        return null;
    }

    if (entry.action === 'merge') {
        return entry.mergeIntoKey
            ? targetCurrency(entry.mergeIntoKey, plan, accounts)
            : null;
    }

    if (entry.action === 'map') {
        return (
            accounts.find((account) => account.id === entry.targetAccountId)
                ?.currency_code ?? entry.currencyCode
        );
    }

    return entry.currencyCode;
}

/* ------------------------------------------------------------------------ */
/* Balances                                                                  */
/* ------------------------------------------------------------------------ */

/**
 * The balance each day ended on, for the rows of one account. The file says
 * nothing about the time of day, so its order decides: an export with the
 * newest movement on top ends a day on the first row of it, one in date
 * order on the last.
 */
export function latestBalances(rows: NormalizedRow[]): Map<string, number> {
    const balances = new Map<string, number>();
    const newestFirst =
        rows.length > 1 && rows[0].date > rows[rows.length - 1].date;

    for (const row of rows) {
        if (row.balance === null) {
            continue;
        }

        if (!newestFirst || !balances.has(row.date)) {
            balances.set(row.date, row.balance);
        }
    }

    return balances;
}

/* ------------------------------------------------------------------------ */
/* Categories                                                                */
/* ------------------------------------------------------------------------ */

function segmentId(part: string): string {
    return normalizeText(part) || part.trim().toLowerCase();
}

/** The node id of a category path: its normalised names, root first. */
export function categoryNodeId(path: string[]): string {
    return path.map(segmentId).join('>');
}

/**
 * The tree the file's category column describes, with how many rows sit
 * under each node and which way their money goes. Parents come before their
 * children, roots ordered by how busy they are.
 */
export function detectCategories(rows: NormalizedRow[]): FileCategoryNode[] {
    const nodes = new Map<string, FileCategoryNode>();

    for (const row of rows) {
        row.categoryPath.forEach((name, depth) => {
            const id = categoryNodeId(row.categoryPath.slice(0, depth + 1));
            const node = nodes.get(id) ?? {
                id,
                name,
                parentId:
                    depth === 0
                        ? null
                        : categoryNodeId(row.categoryPath.slice(0, depth)),
                depth,
                count: 0,
                outgoing: 0,
                incoming: 0,
            };

            node.count += depth === row.categoryPath.length - 1 ? 1 : 0;
            node.outgoing += row.amount < 0 ? 1 : 0;
            node.incoming += row.amount > 0 ? 1 : 0;
            nodes.set(id, node);
        });
    }

    const all = [...nodes.values()];
    const ordered: FileCategoryNode[] = [];
    const visit = (parentId: string | null) => {
        all.filter((node) => node.parentId === parentId)
            .sort(
                (a, b) =>
                    b.outgoing + b.incoming - (a.outgoing + a.incoming) ||
                    a.name.localeCompare(b.name),
            )
            .forEach((node) => {
                ordered.push(node);
                visit(node.id);
            });
    };
    visit(null);

    return ordered;
}

/** "Traspasos Propios": movements between the user's own accounts. */
export function isOwnTransferNode(
    node: FileCategoryNode,
    nodes: FileCategoryNode[],
): boolean {
    return (
        node.parentId === null &&
        OWN_TRANSFER_NAMES.has(normalizeText(node.name)) &&
        !nodes.some((other) => other.parentId === node.id)
    );
}

const STOPWORDS = new Set([
    'y',
    'e',
    'de',
    'del',
    'la',
    'el',
    'los',
    'las',
    'en',
    'para',
    'and',
    'of',
    'the',
    'for',
]);

function words(name: string): string[] {
    return normalizeText(name)
        .split(' ')
        .filter((word) => word !== '' && !STOPWORDS.has(word));
}

/** A word and its singular, the cheap way: "supermercados", "comisiones". */
function wordForms(word: string): Set<string> {
    const forms = new Set([word]);

    if (word.length > 3 && word.endsWith('s')) {
        forms.add(word.slice(0, -1));
    }

    if (word.length > 4 && word.endsWith('es')) {
        forms.add(word.slice(0, -2));
    }

    return forms;
}

function sameWord(a: string, b: string): boolean {
    const forms = wordForms(a);

    return [...wordForms(b)].some((form) => forms.has(form));
}

function sameWords(a: string[], b: string[]): boolean {
    return (
        a.length > 0 &&
        a.length === b.length &&
        a.every((word, index) => sameWord(word, b[index]))
    );
}

function containsWords(small: string[], large: string[]): boolean {
    return (
        small.length > 0 &&
        small.every((word) => large.some((other) => sameWord(word, other)))
    );
}

/**
 * The user's category a file category is most likely to be: the same name,
 * the same seeded category in the other language, the same words up to a
 * plural — all certain — or one whose words contain the other's, which is
 * only a suggestion to review.
 */
export function matchCategory(
    name: string,
    candidates: Category[],
    defaultNames: { en: string; es: string }[],
): { category: Category; kind: CategoryMatchKind } | null {
    const normalized = normalizeText(name);
    const exact = candidates.find(
        (category) => normalizeText(category.name) === normalized,
    );

    if (exact) {
        return { category: exact, kind: 'exact' };
    }

    const seeded = defaultNames.find(
        (pair) =>
            normalizeText(pair.en) === normalized ||
            normalizeText(pair.es) === normalized,
    );
    const translated =
        seeded &&
        candidates.find((category) =>
            [seeded.en, seeded.es]
                .map(normalizeText)
                .includes(normalizeText(category.name)),
        );

    if (translated) {
        return { category: translated, kind: 'exact' };
    }

    const own = words(name);
    const stemmed = candidates.find((category) =>
        sameWords(own, words(category.name)),
    );

    if (stemmed) {
        return { category: stemmed, kind: 'exact' };
    }

    const similar = candidates
        .filter((category) => {
            const theirs = words(category.name);

            return containsWords(own, theirs) || containsWords(theirs, own);
        })
        .sort(
            (a, b) =>
                Math.abs(words(a.name).length - own.length) -
                Math.abs(words(b.name).length - own.length),
        )[0];

    return similar ? { category: similar, kind: 'similar' } : null;
}

/** Every category under one of the user's, at any depth. */
function descendantsOf(categoryId: string, categories: Category[]): Category[] {
    const children = categories.filter(
        (category) => category.parent_id === categoryId,
    );

    return children.flatMap((child) => [
        child,
        ...descendantsOf(child.id, categories),
    ]);
}

/** How deep one of the user's categories sits, a root being 1. */
export function categoryDepth(
    categoryId: string,
    categories: Category[],
): number {
    const byId = new Map(categories.map((category) => [category.id, category]));
    let depth = 0;
    let current = byId.get(categoryId);

    while (current && depth <= MAX_CATEGORY_DEPTH) {
        depth++;
        current = current.parent_id ? byId.get(current.parent_id) : undefined;
    }

    return depth;
}

export interface CategoryPlanContext {
    categories: Category[];
    defaultNames: { en: string; es: string }[];
}

/** Money mostly going out reads as an expense, mostly coming in as income. */
function inferredType(node: FileCategoryNode): CategoryType {
    return node.outgoing >= node.incoming ? 'expense' : 'income';
}

function defaultCategoryEntry(
    node: FileCategoryNode,
    parent: CategoryPlanEntry | null,
    context: CategoryPlanContext,
): CategoryPlanEntry {
    if (parent?.action === 'create') {
        return {
            action: 'create',
            categoryId: null,
            matchKind: null,
            type: parent.type,
        };
    }

    const candidates =
        parent?.categoryId != null
            ? descendantsOf(parent.categoryId, context.categories)
            : context.categories;
    const match = matchCategory(node.name, candidates, context.defaultNames);

    if (match) {
        return {
            action: 'match',
            categoryId: match.category.id,
            matchKind: match.kind,
            type: match.category.type,
        };
    }

    const parentCategory = context.categories.find(
        (category) => category.id === parent?.categoryId,
    );

    return {
        action: 'create',
        categoryId: null,
        matchKind: null,
        type: parentCategory?.type ?? inferredType(node),
    };
}

/**
 * The plan the import will run with for every category of the file, worked
 * out parents first because a child's default depends on its parent's: under
 * a matched category it is looked for among that category's children, under
 * a new one it is new too. A new child takes its parent's type, which is the
 * rule the server enforces.
 */
export function resolveCategoryPlan(
    nodes: FileCategoryNode[],
    overrides: Record<string, Partial<CategoryPlanEntry>>,
    context: CategoryPlanContext,
): Record<string, CategoryPlanEntry> {
    const plan: Record<string, CategoryPlanEntry> = {};

    for (const node of nodes) {
        const parent = node.parentId ? (plan[node.parentId] ?? null) : null;
        const entry = {
            ...defaultCategoryEntry(node, parent, context),
            ...overrides[node.id],
        };

        if (entry.action === 'create' && parent) {
            entry.type =
                parent.action === 'create'
                    ? parent.type
                    : (context.categories.find(
                          (category) => category.id === parent.categoryId,
                      )?.type ?? entry.type);
        }

        plan[node.id] = entry;
    }

    return plan;
}

/* ------------------------------------------------------------------------ */
/* Payload                                                                   */
/* ------------------------------------------------------------------------ */

const ICON_BY_TYPE: Record<CategoryType, string> = {
    expense: 'Wallet',
    income: 'Coins',
    transfer: 'ArrowLeftRight',
    savings: 'PiggyBank',
    investment: 'TrendingUp',
};

const ROOT_COLORS: CategoryColor[] = [
    'blue',
    'green',
    'orange',
    'purple',
    'pink',
    'teal',
    'amber',
    'indigo',
    'rose',
    'cyan',
    'lime',
    'violet',
];

/** Where a kind of transfer goes: one of the user's categories, or the default one to create. */
export interface TransferChoice {
    categoryId: string | null;
    target: TransferTarget;
}

export interface BuildImportInput {
    source: FullImportSource;
    fileName: string | null;
    mode: FullImportMode;
    mapping: FullImportMapping;
    rows: NormalizedRow[];
    fileAccounts: FileAccount[];
    accountPlan: Record<string, AccountPlanEntry>;
    contextAccounts: ContextAccount[];
    nodes: FileCategoryNode[];
    categoryPlan: Record<string, CategoryPlanEntry>;
    categories: Category[];
    transfers: { own: TransferChoice; ignored: TransferChoice };
}

export interface BuiltImport {
    payload: ImportPayload;
    transactions: TransactionPayloadRow[];
    balances: BalancePayloadRow[];
}

/**
 * The key the plan gives the file account at this position. Short on purpose:
 * every one of thousands of uploaded rows repeats it.
 */
export function accountPayloadKey(index: number): string {
    return `a${index}`;
}

function accountEntries(input: BuildImportInput): {
    entries: AccountPayloadEntry[];
    keys: Map<string, string>;
} {
    const keys = new Map(
        input.fileAccounts.map((account, index) => [
            account.key,
            accountPayloadKey(index),
        ]),
    );

    const entries = input.fileAccounts.map((account): AccountPayloadEntry => {
        const entry = input.accountPlan[account.key];
        const key = keys.get(account.key) as string;

        switch (entry.action) {
            case 'create':
                return {
                    key,
                    action: 'create',
                    name: entry.name.trim() || account.name,
                    type: entry.type,
                    currency_code: entry.currencyCode,
                    bank_id: entry.bank?.id ?? null,
                    iban: account.iban,
                };
            case 'map':
                return {
                    key,
                    action: 'map',
                    target_account_id: entry.targetAccountId as string,
                };
            case 'merge':
                return {
                    key,
                    action: 'merge',
                    merge_into_key: keys.get(entry.mergeIntoKey as string),
                };
            default:
                return { key, action: 'skip' };
        }
    });

    return { entries, keys };
}

/**
 * The plan entry of every category node, and the key each node's rows are
 * filed under. A new node that would sit deeper than the tree allows has no
 * entry of its own: its rows go to its parent.
 */
function nodeKeys(input: BuildImportInput): {
    entries: CategoryPayloadEntry[];
    keys: Map<string, string>;
} {
    const keys = new Map<string, string>();
    const depths = new Map<string, number>();
    const colors = new Map<string, CategoryColor>();
    const entries: CategoryPayloadEntry[] = [];
    let rootIndex = 0;

    input.nodes.forEach((node, index) => {
        if (isOwnTransferNode(node, input.nodes)) {
            return;
        }

        const entry = input.categoryPlan[node.id];
        const key = `c${index}`;
        const parentKey = node.parentId
            ? (keys.get(node.parentId) ?? null)
            : null;

        if (entry.action === 'match' && entry.categoryId) {
            keys.set(node.id, key);
            depths.set(
                node.id,
                categoryDepth(entry.categoryId, input.categories),
            );
            entries.push({
                key,
                action: 'match',
                category_id: entry.categoryId,
            });

            return;
        }

        const depth = node.parentId ? (depths.get(node.parentId) ?? 0) + 1 : 1;

        if (depth > MAX_CATEGORY_DEPTH && parentKey) {
            // No room for another level: its rows go to the parent.
            keys.set(node.id, parentKey);
            depths.set(node.id, depth - 1);

            return;
        }

        const color =
            (node.parentId && colors.get(node.parentId)) ||
            ROOT_COLORS[rootIndex++ % ROOT_COLORS.length];

        keys.set(node.id, key);
        depths.set(node.id, depth);
        colors.set(node.id, color);
        entries.push({
            key,
            action: 'create',
            name: node.name,
            parent_key: parentKey,
            type: entry.type,
            icon: ICON_BY_TYPE[entry.type],
            color,
        });
    });

    return { entries, keys };
}

function transferEntry(
    key: string,
    choice: TransferChoice,
): CategoryPayloadEntry {
    return choice.categoryId
        ? { key, action: 'match', category_id: choice.categoryId }
        : {
              key,
              action: 'create',
              name: choice.target.name,
              parent_key: null,
              type: 'transfer',
              icon: choice.target.icon,
              color: choice.target.color,
          };
}

function categoryKeyFor(
    row: NormalizedRow,
    keys: Map<string, string>,
    ownNodeIds: Set<string>,
): string | null {
    if (row.ignored) {
        return IGNORED_KEY;
    }

    if (row.categoryPath.length === 0) {
        return null;
    }

    const id = categoryNodeId(row.categoryPath);

    return ownNodeIds.has(id) ? OWN_TRANSFER_KEY : (keys.get(id) ?? null);
}

/** The rows of the file that will be imported: every account but the skipped ones. */
export function importedRows(
    rows: NormalizedRow[],
    plan: Record<string, AccountPlanEntry>,
): NormalizedRow[] {
    return rows.filter(
        (row) => plan[row.accountKey] && plan[row.accountKey].action !== 'skip',
    );
}

function balanceRows(
    input: BuildImportInput,
    rows: NormalizedRow[],
    accountKeys: Map<string, string>,
): BalancePayloadRow[] {
    const balances: BalancePayloadRow[] = [];

    for (const account of input.fileAccounts) {
        const action = input.accountPlan[account.key]?.action;
        const currency = targetCurrency(
            account.key,
            input.accountPlan,
            input.contextAccounts,
        );

        if ((action !== 'create' && action !== 'map') || !currency) {
            continue;
        }

        const daily = latestBalances(
            rows.filter((row) => row.accountKey === account.key),
        );

        for (const [date, balance] of daily) {
            balances.push({
                account_key: accountKeys.get(account.key) as string,
                date,
                balance: toMinorUnits(balance, currency),
            });
        }
    }

    return balances;
}

/**
 * Everything the server takes: the plan, then the movements and balances it
 * is uploaded in chunks with. Only the rows of imported accounts are sent;
 * merged ones keep their own key, the server knows where it goes.
 */
export function buildImport(input: BuildImportInput): BuiltImport {
    const rows = importedRows(input.rows, input.accountPlan);
    const accounts = accountEntries(input);
    const categories = nodeKeys(input);
    const ownNodeIds = new Set(
        input.nodes
            .filter((node) => isOwnTransferNode(node, input.nodes))
            .map((node) => node.id),
    );

    const transactions = rows.map((row): TransactionPayloadRow => {
        const currency =
            targetCurrency(
                row.accountKey,
                input.accountPlan,
                input.contextAccounts,
            ) ?? 'EUR';

        return {
            account_key: accounts.keys.get(row.accountKey) as string,
            date: row.date,
            amount: toMinorUnits(row.amount, row.currency ?? currency),
            description: row.description.slice(0, 2000),
            notes: row.notes ? row.notes.slice(0, 5000) : null,
            category_key: categoryKeyFor(row, categories.keys, ownNodeIds),
            external_id: row.externalId,
            currency_code: row.currency,
        };
    });

    const used = new Set(transactions.map((row) => row.category_key));
    const transferEntries = [
        used.has(OWN_TRANSFER_KEY)
            ? transferEntry(OWN_TRANSFER_KEY, input.transfers.own)
            : null,
        used.has(IGNORED_KEY)
            ? transferEntry(IGNORED_KEY, input.transfers.ignored)
            : null,
    ].filter((entry): entry is CategoryPayloadEntry => entry !== null);

    const balances = input.mapping.balance
        ? balanceRows(input, rows, accounts.keys)
        : [];

    return {
        payload: {
            source: input.source,
            file_name: input.fileName,
            mode: input.mode,
            ...(input.mode === 'wipe' ? { confirm_wipe: true as const } : {}),
            profile: toSavedProfile(input.mapping),
            accounts: accounts.entries,
            categories: [...transferEntries, ...categories.entries],
            expected_transactions: transactions.length,
            expected_balances: balances.length,
        },
        transactions,
        balances,
    };
}
