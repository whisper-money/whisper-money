import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { __ } from '@/utils/i18n';
import { type LucideIcon } from 'lucide-react';
import { toast } from 'sonner';

export function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : __('Never');
}

/**
 * Options for the visit a confirmed action makes. A refused one (the
 * shared-account block, an expired token) comes back as an error bag; say why
 * instead of closing the dialog silently.
 */
export const confirmedActionVisitOptions = {
    preserveScroll: true,
    preserveState: true,
    onError: (errors: Record<string, string>) =>
        toast.error(Object.values(errors)[0] ?? __('Something went wrong.')),
};

/**
 * A token or app action that asks for confirmation first, since rotating and
 * revoking cut off whatever uses the current secret.
 */
export function ConfirmTokenAction({
    icon: Icon,
    label,
    title,
    description,
    destructive = false,
    onConfirm,
}: {
    icon: LucideIcon;
    label: string;
    title: string;
    description: string;
    destructive?: boolean;
    onConfirm: () => void;
}) {
    return (
        <AlertDialog>
            <AlertDialogTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className={destructive ? 'text-destructive' : undefined}
                >
                    <Icon className="h-4 w-4" />
                    {label}
                </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {description}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>{__('Cancel')}</AlertDialogCancel>
                    <AlertDialogAction onClick={onConfirm}>
                        {label}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
