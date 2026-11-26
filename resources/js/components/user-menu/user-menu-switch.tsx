import { cn } from '@/lib/utils';

/**
 * The look of a switch for a menu row that already carries the switch's role
 * and state (`menuitemcheckbox` on desktop, `switch` on the phone), so this
 * part stays out of the accessibility tree.
 */
export function UserMenuSwitch({
    checked,
    size = 'sm',
}: {
    checked: boolean;
    size?: 'sm' | 'md';
}) {
    return (
        <span
            aria-hidden="true"
            data-state={checked ? 'checked' : 'unchecked'}
            className={cn(
                'relative ml-auto inline-flex shrink-0 items-center rounded-full transition-colors',
                checked ? 'bg-primary' : 'bg-input dark:bg-input/80',
                size === 'sm' ? 'h-[18px] w-[30px]' : 'h-6 w-10',
            )}
        >
            <span
                className={cn(
                    'absolute rounded-full bg-background shadow-xs transition-[left] dark:bg-foreground',
                    checked && 'dark:bg-primary-foreground',
                    size === 'sm'
                        ? cn('size-3.5', checked ? 'left-3.5' : 'left-0.5')
                        : cn(
                              'size-[18px]',
                              checked ? 'left-[19px]' : 'left-[3px]',
                          ),
                )}
            />
        </span>
    );
}
