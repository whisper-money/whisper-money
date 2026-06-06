import * as React from 'react';

import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { Eye, EyeOff } from 'lucide-react';

type PasswordInputProps = Omit<React.ComponentProps<'input'>, 'type'>;

const PasswordInput = React.forwardRef<HTMLInputElement, PasswordInputProps>(
    ({ className, ...props }, ref) => {
        const [visible, setVisible] = React.useState(false);

        return (
            <div className="relative">
                <Input
                    ref={ref}
                    type={visible ? 'text' : 'password'}
                    className={cn(
                        'pr-10 [&::-ms-reveal]:hidden [&::-ms-clear]:hidden',
                        className,
                    )}
                    {...props}
                />
                <button
                    type="button"
                    onClick={() => setVisible((current) => !current)}
                    disabled={props.disabled}
                    tabIndex={-1}
                    aria-label={
                        visible ? __('Hide password') : __('Show password')
                    }
                    aria-pressed={visible}
                    className="absolute inset-y-0 right-0 flex items-center justify-center px-3 text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:text-foreground disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {visible ? (
                        <EyeOff className="size-4" />
                    ) : (
                        <Eye className="size-4" />
                    )}
                </button>
            </div>
        );
    },
);
PasswordInput.displayName = 'PasswordInput';

export { PasswordInput };
