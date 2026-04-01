import { index as indexBanks } from '@/actions/App/Http/Controllers/Settings/BankController';
import { BankLogo } from '@/components/bank-logo';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { type Bank } from '@/types/account';
import { __ } from '@/utils/i18n';
import { Check, ChevronsUpDown, Plus } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface BankComboboxProps {
    value: string | null;
    onValueChange: (value: string | null) => void;
    defaultBank?: Bank;
    onCreateCustomBank?: (searchQuery: string) => void;
}

const bankCache = new Map<string, Bank[]>();

export function BankCombobox({
    value,
    onValueChange,
    defaultBank,
    onCreateCustomBank,
}: BankComboboxProps) {
    const [open, setOpen] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const [banks, setBanks] = useState<Bank[]>(
        defaultBank ? [defaultBank] : [],
    );
    const [isLoading, setIsLoading] = useState(false);
    const [selectedBank, setSelectedBank] = useState<Bank | null>(
        defaultBank || null,
    );

    const searchBanks = useCallback(
        async (query: string) => {
            if (query.length < 3) {
                setBanks(defaultBank ? [defaultBank] : []);
                return;
            }

            if (bankCache.has(query)) {
                setBanks(bankCache.get(query)!);
                return;
            }

            setIsLoading(true);
            try {
                const response = await fetch(
                    indexBanks.url({ query: { search: query } }),
                    {
                        headers: {
                            Accept: 'application/json',
                        },
                    },
                );
                if (!response.ok) {
                    throw new Error('Failed to search banks');
                }
                const data = await response.json();
                const results = data.data || data;
                bankCache.set(query, results);
                setBanks(results);
            } catch (error) {
                console.error('Failed to search banks:', error);
                setBanks([]);
            } finally {
                setIsLoading(false);
            }
        },
        [defaultBank],
    );

    useEffect(() => {
        const debounceTimer = setTimeout(() => {
            searchBanks(searchQuery);
        }, 300);

        return () => clearTimeout(debounceTimer);
    }, [searchQuery, searchBanks]);

    const handleSelect = (bank: Bank) => {
        setSelectedBank(bank);
        onValueChange(bank.id);
        setOpen(false);
    };

    return (
        <Popover open={open} onOpenChange={setOpen} modal={true}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    className="w-full justify-between"
                    data-testid="bank-select"
                >
                    {selectedBank ? (
                        <div className="flex items-center gap-2">
                            <BankLogo
                                src={selectedBank.logo}
                                name={selectedBank.name}
                                className="h-4 w-4"
                                fallback="empty"
                            />
                            <span>{selectedBank.name}</span>
                        </div>
                    ) : (
                        __('Select bank...')
                    )}
                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-[var(--radix-popover-trigger-width)] p-0"
                align="start"
            >
                <Command filter={() => 1}>
                    <CommandInput
                        placeholder={__('Search bank...')}
                        value={searchQuery}
                        onValueChange={setSearchQuery}
                    />

                    <CommandList>
                        <CommandEmpty>
                            {isLoading
                                ? __('Searching...')
                                : searchQuery.length < 3
                                  ? __('Type at least 3 characters to search')
                                  : __('No bank found.')}
                        </CommandEmpty>
                        <CommandGroup>
                            {banks.map((bank) => (
                                <CommandItem
                                    key={bank.id}
                                    value={`${bank.id}-${bank.name}`}
                                    onSelect={() => handleSelect(bank)}
                                >
                                    <div className="flex items-center gap-2">
                                        <BankLogo
                                            src={bank.logo}
                                            name={bank.name}
                                            className="h-4 w-4"
                                            fallback="empty"
                                        />
                                        <span>{bank.name}</span>
                                    </div>
                                    <Check
                                        className={cn(
                                            'ml-auto h-4 w-4',
                                            value === bank.id
                                                ? 'opacity-100'
                                                : 'opacity-0',
                                        )}
                                    />
                                </CommandItem>
                            ))}
                        </CommandGroup>
                        {onCreateCustomBank &&
                            searchQuery.length >= 3 &&
                            !isLoading &&
                            banks.length < 3 && (
                                <>
                                    <CommandSeparator />
                                    <CommandGroup>
                                        <CommandItem
                                            value="create-custom-bank"
                                            onSelect={() => {
                                                onCreateCustomBank(searchQuery);
                                                setOpen(false);
                                            }}
                                        >
                                            <Plus className="mr-2 h-4 w-4" />
                                            <span>
                                                {__('Create "')}
                                                {searchQuery}&quot;
                                            </span>
                                        </CommandItem>
                                    </CommandGroup>
                                </>
                            )}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
