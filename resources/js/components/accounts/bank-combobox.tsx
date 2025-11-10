import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { bankSyncService } from '@/services/bank-sync';
import { type Bank } from '@/types/account';
import { Check, ChevronsUpDown } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface BankComboboxProps {
    value: number | null;
    onValueChange: (value: number | null) => void;
    defaultBank?: Bank;
}

const bankCache = new Map<string, Bank[]>();

export function BankCombobox({
    value,
    onValueChange,
    defaultBank,
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
                const results = await bankSyncService.search(query);
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
                >
                    {selectedBank ? (
                        <div className="flex items-center gap-2">
                            {selectedBank.logo ? (
                                <img
                                    src={selectedBank.logo}
                                    alt={selectedBank.name}
                                    className="h-4 w-4 rounded object-contain"
                                />
                            ) : (
                                <div className="h-4 w-4 rounded bg-muted" />
                            )}
                            <span>{selectedBank.name}</span>
                        </div>
                    ) : (
                        'Select bank...'
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
                        placeholder="Search bank..."
                        value={searchQuery}
                        onValueChange={setSearchQuery}
                    />
                    <CommandList>
                        <CommandEmpty>
                            {isLoading
                                ? 'Searching...'
                                : searchQuery.length < 3
                                  ? 'Type at least 3 characters to search'
                                  : 'No bank found.'}
                        </CommandEmpty>
                        <CommandGroup>
                            {banks.map((bank) => (
                                <CommandItem
                                    key={bank.id}
                                    value={`${bank.id}-${bank.name}`}
                                    onSelect={() => handleSelect(bank)}
                                >
                                    <div className="flex items-center gap-2">
                                        {bank.logo ? (
                                            <img
                                                src={bank.logo}
                                                alt={bank.name}
                                                className="h-4 w-4 rounded object-contain"
                                            />
                                        ) : (
                                            <div className="h-4 w-4 rounded bg-muted" />
                                        )}
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
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
