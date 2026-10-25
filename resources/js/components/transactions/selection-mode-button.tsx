import { Button } from '@/components/ui/button';
import { type MobileSelectionMode } from '@/hooks/use-mobile-selection-mode';
import { __ } from '@/utils/i18n';

/**
 * The toolbar's way in and out of selection mode on the phone, for whoever
 * does not know to long-press a row. Off the phone the checkbox column does
 * the job, so this renders nothing.
 */
export function SelectionModeButton({
    selectionMode,
}: {
    selectionMode: MobileSelectionMode;
}) {
    if (!selectionMode.isMobile) {
        return null;
    }

    return selectionMode.isActive ? (
        <Button
            type="button"
            size="sm"
            className="rounded-full px-4"
            onClick={selectionMode.exit}
            data-testid="selection-mode-done"
        >
            {__('Done')}
        </Button>
    ) : (
        <Button
            type="button"
            size="sm"
            variant="outline"
            className="rounded-full px-3.5"
            onClick={selectionMode.enter}
            data-testid="selection-mode-select"
        >
            {__('Select')}
        </Button>
    );
}
