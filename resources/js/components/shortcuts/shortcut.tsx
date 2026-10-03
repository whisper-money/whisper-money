import { useShortcut } from '@/hooks/use-shortcut';
import { type ShortcutId } from '@/lib/shortcut-catalog';

/**
 * `useShortcut` as an element, for a component that renders its own dialog:
 * placed inside `DialogContent`, the shortcut lands on the dialog's layer
 * rather than on the page the component itself sits in. Renders nothing.
 */
export function Shortcut({
    id,
    onTrigger,
    enabled = true,
}: {
    id: ShortcutId;
    onTrigger: (event: KeyboardEvent) => void;
    enabled?: boolean;
}) {
    useShortcut(id, onTrigger, { enabled });

    return null;
}
