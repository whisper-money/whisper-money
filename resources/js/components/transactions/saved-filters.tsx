import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    deserializeFilters,
    filtersFingerprint,
    hasActiveFilters,
    type SerializedFilters,
    serializeFilters,
} from '@/lib/transaction-filter-serialization';
import { cn } from '@/lib/utils';
import { type TransactionFilters } from '@/types/transaction';
import { type UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';
import axios from 'axios';
import {
    Bookmark,
    BookmarkCheck,
    Check,
    Plus,
    Save,
    Trash2,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

interface SavedFilter {
    id: UUID;
    name: string;
    filters: SerializedFilters;
}

interface SavedFiltersProps {
    filters: TransactionFilters;
    onLoad: (filters: TransactionFilters) => void;
}

export function SavedFilters({ filters, onLoad }: SavedFiltersProps) {
    const [savedFilters, setSavedFilters] = useState<SavedFilter[]>([]);
    const [activeId, setActiveId] = useState<UUID | null>(null);
    const [saveDialogOpen, setSaveDialogOpen] = useState(false);
    const [name, setName] = useState('');
    const [isSaving, setIsSaving] = useState(false);

    const canSave = hasActiveFilters(filters);

    const currentFingerprint = useMemo(
        () => filtersFingerprint(serializeFilters(filters)),
        [filters],
    );

    // The saved filter that exactly matches the current filters, if any.
    const matchingSaved = useMemo(
        () =>
            savedFilters.find(
                (savedFilter) =>
                    filtersFingerprint(savedFilter.filters) ===
                    currentFingerprint,
            ) ?? null,
        [savedFilters, currentFingerprint],
    );

    // Keep track of the saved filter we're working from: it follows an exact
    // match, and otherwise sticks around (so edits can be saved over it) until
    // the filters are cleared.
    useEffect(() => {
        if (matchingSaved) {
            setActiveId(matchingSaved.id);
        } else if (!canSave) {
            setActiveId(null);
        }
    }, [matchingSaved, canSave]);

    const activeFilter =
        savedFilters.find((savedFilter) => savedFilter.id === activeId) ?? null;
    const isDirty = activeFilter !== null && matchingSaved === null;

    useEffect(() => {
        let active = true;

        axios
            .get<{ data: SavedFilter[] }>('/api/saved-filters')
            .then((response) => {
                if (active) {
                    setSavedFilters(response.data.data);
                }
            })
            .catch((error) => {
                console.error('Failed to load saved filters:', error);
            });

        return () => {
            active = false;
        };
    }, []);

    function handleLoad(savedFilter: SavedFilter) {
        setActiveId(savedFilter.id);
        onLoad(deserializeFilters(savedFilter.filters));
    }

    async function handleDelete(savedFilter: SavedFilter) {
        const previous = savedFilters;
        setSavedFilters((current) =>
            current.filter((item) => item.id !== savedFilter.id),
        );
        if (activeId === savedFilter.id) {
            setActiveId(null);
        }

        try {
            await axios.delete(`/api/saved-filters/${savedFilter.id}`);
        } catch (error) {
            console.error('Failed to delete saved filter:', error);
            setSavedFilters(previous);
            toast.error(__('Failed to delete the saved filter'));
        }
    }

    async function handleUpdate(savedFilter: SavedFilter) {
        try {
            const response = await axios.patch<{ data: SavedFilter }>(
                `/api/saved-filters/${savedFilter.id}`,
                { filters: serializeFilters(filters) },
            );

            setSavedFilters((current) =>
                current.map((item) =>
                    item.id === savedFilter.id ? response.data.data : item,
                ),
            );
            setActiveId(savedFilter.id);
            toast.success(__('Filter updated'));
        } catch (error) {
            console.error('Failed to update saved filter:', error);
            toast.error(__('Failed to update the saved filter'));
        }
    }

    async function handleSave() {
        const trimmedName = name.trim();
        if (!trimmedName || isSaving) {
            return;
        }

        setIsSaving(true);
        try {
            const response = await axios.post<{ data: SavedFilter }>(
                '/api/saved-filters',
                {
                    name: trimmedName,
                    filters: serializeFilters(filters),
                },
            );

            setSavedFilters((current) =>
                [...current, response.data.data].sort((a, b) =>
                    a.name.localeCompare(b.name),
                ),
            );
            setActiveId(response.data.data.id);
            setSaveDialogOpen(false);
            setName('');
            toast.success(__('Filter saved'));
        } catch (error) {
            if (axios.isAxiosError(error) && error.response?.status === 422) {
                toast.error(__('A filter with that name already exists'));
            } else {
                console.error('Failed to save filter:', error);
                toast.error(__('Failed to save the filter'));
            }
        } finally {
            setIsSaving(false);
        }
    }

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    {activeFilter ? (
                        <Button
                            variant="secondary"
                            className="max-w-[200px]"
                            aria-label={__('Saved filters')}
                        >
                            <BookmarkCheck className="mr-1 h-4 w-4 shrink-0" />
                            <span className="truncate">
                                {activeFilter.name}
                            </span>
                            {isDirty && (
                                <span
                                    className="ml-1 text-muted-foreground"
                                    title={__('Unsaved changes')}
                                >
                                    •
                                </span>
                            )}
                        </Button>
                    ) : (
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label={__('Saved filters')}
                        >
                            <Bookmark className="h-4 w-4" />
                        </Button>
                    )}
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-64">
                    <DropdownMenuLabel>{__('Saved filters')}</DropdownMenuLabel>
                    <DropdownMenuSeparator />

                    {savedFilters.length === 0 ? (
                        <div className="px-2 py-2 text-sm text-muted-foreground">
                            {__('No saved filters yet')}
                        </div>
                    ) : (
                        savedFilters.map((savedFilter) => (
                            <DropdownMenuItem
                                key={savedFilter.id}
                                onSelect={() => handleLoad(savedFilter)}
                                className="group justify-between gap-2"
                            >
                                <span className="flex min-w-0 items-center gap-2">
                                    <Check
                                        className={cn(
                                            'h-3.5 w-3.5 shrink-0',
                                            matchingSaved?.id === savedFilter.id
                                                ? 'opacity-100'
                                                : 'opacity-0',
                                        )}
                                    />
                                    <span className="truncate">
                                        {savedFilter.name}
                                    </span>
                                </span>
                                <button
                                    type="button"
                                    aria-label={__('Delete saved filter')}
                                    className="shrink-0 rounded p-1 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 hover:text-destructive"
                                    onClick={(event) => {
                                        event.preventDefault();
                                        event.stopPropagation();
                                        handleDelete(savedFilter);
                                    }}
                                >
                                    <Trash2 className="h-3.5 w-3.5" />
                                </button>
                            </DropdownMenuItem>
                        ))
                    )}

                    <DropdownMenuSeparator />

                    {isDirty && activeFilter && (
                        <DropdownMenuItem
                            onSelect={(event) => {
                                event.preventDefault();
                                handleUpdate(activeFilter);
                            }}
                        >
                            <Save className="mr-1 h-4 w-4" />
                            {__('Update “:name”', { name: activeFilter.name })}
                        </DropdownMenuItem>
                    )}

                    <DropdownMenuItem
                        disabled={!canSave}
                        onSelect={(event) => {
                            event.preventDefault();
                            setSaveDialogOpen(true);
                        }}
                    >
                        <Plus className="mr-1 h-4 w-4" />
                        {__('Save as new filter…')}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={saveDialogOpen} onOpenChange={setSaveDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{__('Save filter')}</DialogTitle>
                        <DialogDescription>
                            {__(
                                'Give this set of filters a name so you can reuse it later.',
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <Input
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        placeholder={__('e.g. Japan trip, Utilities')}
                        autoFocus
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                handleSave();
                            }
                        }}
                    />
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setSaveDialogOpen(false)}
                            disabled={isSaving}
                        >
                            {__('Cancel')}
                        </Button>
                        <Button
                            onClick={handleSave}
                            disabled={isSaving || !name.trim()}
                        >
                            {isSaving ? __('Saving…') : __('Save')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
