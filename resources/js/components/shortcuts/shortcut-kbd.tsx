import { Kbd } from '@/components/ui/kbd';
import { useShortcutHint } from '@/hooks/use-shortcut';
import { type ShortcutId } from '@/lib/shortcut-catalog';

/**
 * The chip for a shortcut in the catalog, in the platform's notation. Hidden
 * from assistive tech: the control it sits in carries `aria-keyshortcuts`
 * instead (see `useShortcutHint`). Renders nothing until the platform is known.
 */
export function ShortcutKbd({
    id,
    className,
}: {
    id: ShortcutId;
    className?: string;
}) {
    const hint = useShortcutHint(id);

    if (!hint) {
        return null;
    }

    return (
        <Kbd aria-hidden="true" className={className}>
            {hint.keys}
        </Kbd>
    );
}
