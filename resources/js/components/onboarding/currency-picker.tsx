import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Drawer,
    DrawerContent,
    DrawerDescription,
    DrawerHeader,
    DrawerTitle,
    DrawerTrigger,
} from '@/components/ui/drawer';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import { type CurrencyOption } from '@/types/account';
import { __ } from '@/utils/i18n';
import { Check, ChevronDown } from 'lucide-react';
import { useState } from 'react';

interface CurrencyPickerProps {
    value: string;
    options: CurrencyOption[];
    onChange: (currencyCode: string) => void;
}

/**
 * The user's currency as a pill that opens the full list: a bottom drawer on
 * the phone, where the thumb is, and a dialog on a desktop.
 *
 * Signup infers the currency from the browser's region and falls back to
 * dollars, so this is the first place someone outside that guess can say so.
 */
export function CurrencyPicker({
    value,
    options,
    onChange,
}: CurrencyPickerProps) {
    const isMobile = useIsMobile();
    const [open, setOpen] = useState(false);
    const name = options.find((option) => option.code === value)?.name;

    const select = (currencyCode: string) => {
        setOpen(false);

        if (currencyCode !== value) {
            onChange(currencyCode);
        }
    };

    const trigger = (
        <button
            type="button"
            aria-label={__('Change currency, currently :currency', {
                currency: name ?? value,
            })}
            className="inline-flex items-center gap-1.5 self-center rounded-full border bg-background px-3 py-1.5 text-sm font-medium text-foreground shadow-xs transition-colors outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            <span>{value}</span>
            {name && (
                <>
                    <span aria-hidden className="text-muted-foreground">
                        ·
                    </span>
                    <span className="text-muted-foreground">{name}</span>
                </>
            )}
            <ChevronDown className="size-4 text-muted-foreground" />
        </button>
    );

    const title = __('Your currency');
    const description = __(
        "We'll show every amount in it. You can change it later in Settings.",
    );
    const list = (
        <CurrencyList value={value} options={options} onSelect={select} />
    );

    if (isMobile) {
        return (
            <Drawer open={open} onOpenChange={setOpen}>
                <DrawerTrigger asChild>{trigger}</DrawerTrigger>
                <DrawerContent>
                    <DrawerHeader>
                        <DrawerTitle>{title}</DrawerTitle>
                        <DrawerDescription>{description}</DrawerDescription>
                    </DrawerHeader>
                    {list}
                </DrawerContent>
            </Drawer>
        );
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="gap-0 overflow-hidden p-0 sm:max-w-md">
                <DialogHeader className="p-6 pb-4">
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                {list}
            </DialogContent>
        </Dialog>
    );
}

function CurrencyList({
    value,
    options,
    onSelect,
}: {
    value: string;
    options: CurrencyOption[];
    onSelect: (currencyCode: string) => void;
}) {
    return (
        // Searched by code and by name alike: someone looking for the rupee
        // types "rupee" as often as "INR".
        <Command
            defaultValue={value}
            className="rounded-none border-t bg-transparent"
        >
            <CommandInput placeholder={__('Search currency...')} />
            <CommandList className="max-h-[50vh] p-1 md:max-h-80">
                <CommandEmpty>{__('No currency found.')}</CommandEmpty>
                {options.map((option) => (
                    <CommandItem
                        key={option.code}
                        value={option.code}
                        keywords={[option.name]}
                        onSelect={() => onSelect(option.code)}
                        className={cn(
                            'gap-3 py-2.5',
                            option.code === value && 'bg-muted',
                        )}
                    >
                        <span className="w-10 font-medium tabular-nums">
                            {option.code}
                        </span>
                        <span className="flex-1 text-muted-foreground">
                            {option.name}
                        </span>
                        {option.code === value && (
                            <>
                                <Check className="size-4" aria-hidden />
                                <span className="sr-only">
                                    {__('Selected')}
                                </span>
                            </>
                        )}
                    </CommandItem>
                ))}
            </CommandList>
        </Command>
    );
}
