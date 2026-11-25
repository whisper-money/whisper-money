import {
    type TransactionRowEditing,
    useTransactionRowEditing,
} from '@/hooks/use-transaction-row-editing';
import { captureEvent } from '@/lib/posthog';
import { type Label } from '@/types/label';
import { type DecryptedTransaction } from '@/types/transaction';
import { act, render } from '@testing-library/react';
import { type ComponentProps, useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { EditTransactionDialog } from './edit-transaction-dialog';
import { TransactionRowDialogs } from './transaction-row-dialogs';

type DialogProps = ComponentProps<typeof EditTransactionDialog>;

const dialogs = vi.hoisted(() => [] as DialogProps[]);
const automate = vi.hoisted(() => ({
    latest: null as { open: boolean; candidate: unknown } | null,
}));

vi.mock('./edit-transaction-dialog', () => ({
    EditTransactionDialog: (props: DialogProps) => {
        dialogs.push(props);

        return null;
    },
}));
vi.mock('@/components/automation-rules/automate-categorization-dialog', () => ({
    AutomateCategorizationDialog: (props: {
        open: boolean;
        candidate: unknown;
    }) => {
        automate.latest = props;

        return null;
    },
}));
const splitDialogs = vi.hoisted(() => ({
    split: null as {
        transaction: DecryptedTransaction | null;
        onSuccess: () => void;
    } | null,
    unsplit: null as {
        transaction: DecryptedTransaction | null;
        onSuccess: () => void;
    } | null,
}));

vi.mock('./split-transaction-dialog', () => ({
    SplitTransactionDialog: (props: {
        transaction: DecryptedTransaction | null;
        onSuccess: () => void;
    }) => {
        splitDialogs.split = props;

        return null;
    },
}));
vi.mock('./unsplit-transaction-dialog', () => ({
    UnsplitTransactionDialog: (props: {
        transaction: DecryptedTransaction | null;
        onSuccess: () => void;
    }) => {
        splitDialogs.unsplit = props;

        return null;
    },
}));
vi.mock('@/components/automation-rules/post-save-apply-rule-prompt', () => ({
    PostSaveApplyRulePrompt: () => null,
}));
vi.mock('@/lib/posthog', () => ({ captureEvent: vi.fn() }));

function renderDialogs() {
    const onDuplicated = vi.fn();
    const onSplitChanged = vi.fn();
    const editing = {
        labels: [],
        editTransaction: null,
        duplicateTransaction: { id: 'a' } as DecryptedTransaction,
        setDuplicateTransaction: vi.fn(),
    } as unknown as TransactionRowEditing;

    dialogs.length = 0;
    render(
        <TransactionRowDialogs
            editing={editing}
            categories={[]}
            accounts={[]}
            banks={[]}
            automationRules={[]}
            onDuplicated={onDuplicated}
            onSplitChanged={onSplitChanged}
        />,
    );

    const duplicate = dialogs.find((props) => props.mode === 'create')!;

    return { duplicate, editing, onDuplicated, onSplitChanged };
}

const NO_LABELS: Label[] = [];

/** The dialogs wired to the real hook, with a button to open a row. */
function RowOpenForEditing({
    row,
    canStartSplits = false,
}: {
    row: DecryptedTransaction;
    canStartSplits?: boolean;
}) {
    const [, setRows] = useState([row]);
    const editing = useTransactionRowEditing({
        initialLabels: NO_LABELS,
        setRows,
        canStartSplits,
    });

    return (
        <>
            <button onClick={() => editing.setEditTransaction(row)}>
                edit
            </button>
            <TransactionRowDialogs
                editing={editing}
                categories={[]}
                accounts={[]}
                banks={[]}
                automationRules={[]}
                onDuplicated={vi.fn()}
                onSplitChanged={vi.fn()}
            />
        </>
    );
}

describe('the row actions from the edit dialog, as a phone reaches them', () => {
    const row = {
        id: 'tx-1',
        category: { id: 'food' },
    } as unknown as DecryptedTransaction;

    function latest(mode: DialogProps['mode']) {
        return dialogs.findLast((props) => props.mode === mode)!;
    }

    function openRow(canStartSplits = false) {
        dialogs.length = 0;
        const { getByText } = render(
            <RowOpenForEditing row={row} canStartSplits={canStartSplits} />,
        );

        act(() => getByText('edit').click());

        return latest('edit');
    }

    it('hands the edit dialog its handlers, and Duplicate opens the duplicate dialog', () => {
        const edit = openRow();

        act(() => {
            edit.onOpenChange(false);
            edit.rowActions?.onDuplicate(row);
        });

        expect(latest('edit').open).toBe(false);
        expect(latest('create')).toEqual(
            expect.objectContaining({ open: true, duplicateFrom: row }),
        );
    });

    it('offers the edit dialog no split while splitting is off', () => {
        expect(openRow(false).onSplit).toBeUndefined();
    });

    it('opens the split dialog from there while splitting is on', () => {
        const edit = openRow(true);

        act(() => {
            edit.onOpenChange(false);
            edit.onSplit?.(row);
        });

        expect(latest('edit').open).toBe(false);
        expect(splitDialogs.split?.transaction).toBe(row);
    });

    it('opens the merge dialog from there on a part of a split', () => {
        const edit = openRow();

        act(() => {
            edit.onOpenChange(false);
            edit.rowActions?.onUnsplit(row);
        });

        expect(splitDialogs.unsplit?.transaction).toBe(row);
    });

    it('opens the automate dialog from there, reporting where it came from', () => {
        const edit = openRow();

        act(() => {
            edit.onOpenChange(false);
            edit.rowActions?.onAutomate(row);
        });

        expect(automate.latest).toEqual(
            expect.objectContaining({
                open: true,
                candidate: { transaction: row, category: row.category },
            }),
        );
        expect(captureEvent).toHaveBeenCalledWith(
            'automation_rule_toast_automatize_clicked',
            { source: 'edit_dialog' },
        );
    });
});

describe('TransactionRowDialogs', () => {
    it('brings a duplicate in once its dialog closes, so "Save and add another" survives', () => {
        const { duplicate, onDuplicated } = renderDialogs();

        act(() => duplicate.onSuccess?.({} as DecryptedTransaction));
        expect(onDuplicated).not.toHaveBeenCalled();

        act(() => duplicate.onOpenChange(false));
        expect(onDuplicated).toHaveBeenCalledOnce();
    });

    it('fetches nothing when the duplicate dialog closes without a save', () => {
        const { duplicate, editing, onDuplicated } = renderDialogs();

        act(() => duplicate.onOpenChange(false));

        expect(editing.setDuplicateTransaction).toHaveBeenCalledWith(null);
        expect(onDuplicated).not.toHaveBeenCalled();
    });

    it('brings the parts in once a split is made or merged back', () => {
        const { onSplitChanged } = renderDialogs();

        act(() => splitDialogs.split?.onSuccess());
        act(() => splitDialogs.unsplit?.onSuccess());

        expect(onSplitChanged).toHaveBeenCalledTimes(2);
    });
});
