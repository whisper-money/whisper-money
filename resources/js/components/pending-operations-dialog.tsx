import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { PendingChange } from '@/lib/dexie-db';
import { formatDistanceToNow } from 'date-fns';

interface PendingOperationsDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    operations: PendingChange[];
}

function getOperationBadgeClass(operation: PendingChange['operation']): string {
    switch (operation) {
        case 'create':
            return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
        case 'update':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
        case 'delete':
            return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
        default:
            return 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200';
    }
}

function formatDataPreview(data: Record<string, unknown>): string {
    if (data.id) {
        const preview = `ID: ${String(data.id).slice(0, 8)}...`;
        if (data.name) {
            return `${preview}, Name: ${data.name}`;
        }
        if (data.description) {
            return `${preview}, ${String(data.description).slice(0, 20)}...`;
        }
        return preview;
    }
    return JSON.stringify(data).slice(0, 50) + '...';
}

export function PendingOperationsDialog({
    open,
    onOpenChange,
    operations,
}: PendingOperationsDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Pending Operations</DialogTitle>
                    <DialogDescription>
                        These operations are waiting to be synced with the
                        server.
                    </DialogDescription>
                </DialogHeader>
                <div className="max-h-96 overflow-y-auto">
                    {operations.length === 0 ? (
                        <div className="flex h-32 items-center justify-center text-muted-foreground">
                            No pending operations
                        </div>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Store</TableHead>
                                    <TableHead>Operation</TableHead>
                                    <TableHead>Data</TableHead>
                                    <TableHead>Time</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {operations.map((op) => (
                                    <TableRow key={op.id}>
                                        <TableCell className="font-medium capitalize">
                                            {op.store.replace('_', ' ')}
                                        </TableCell>
                                        <TableCell>
                                            <span
                                                className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${getOperationBadgeClass(op.operation)}`}
                                            >
                                                {op.operation}
                                            </span>
                                        </TableCell>
                                        <TableCell className="max-w-48 truncate text-xs text-muted-foreground">
                                            {formatDataPreview(op.data)}
                                        </TableCell>
                                        <TableCell className="text-xs text-muted-foreground">
                                            {formatDistanceToNow(
                                                new Date(op.timestamp),
                                                { addSuffix: true },
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
