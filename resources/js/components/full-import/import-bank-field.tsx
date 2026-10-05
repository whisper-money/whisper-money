import { BankCombobox } from '@/components/accounts/bank-combobox';
import { BankLogo } from '@/components/bank-logo';
import { Button } from '@/components/ui/button';
import { type BankLite } from '@/types/full-import';
import { __ } from '@/utils/i18n';

export interface BankChoice {
    bank: BankLite | null;
    newBankName: string | null;
}

interface ImportBankFieldProps extends BankChoice {
    /** The bank the file names the account after, offered as a new one. */
    suggestedName: string;
    /** Remounts the search once the bank guesses arrive, so it shows them. */
    version: string;
    onChange: (choice: BankChoice) => void;
}

/**
 * The bank of an account the import creates: a known one, none, or a new one
 * created along with it. A new bank is shown as what it is, a name that does
 * not exist yet, so it never reads as a match that was found.
 */
export function ImportBankField({
    bank,
    newBankName,
    suggestedName,
    version,
    onChange,
}: ImportBankFieldProps) {
    if (newBankName) {
        return (
            <div className="flex min-h-9 items-center gap-2 rounded-md border px-3 py-1 text-sm shadow-xs">
                <BankLogo
                    name={newBankName}
                    fallback="letter"
                    className="size-5 shrink-0 text-[10px]"
                />
                <span className="min-w-0 flex-1 truncate">
                    {__('New bank «:name»', { name: newBankName })}
                </span>
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 px-2"
                    onClick={() => onChange({ bank: null, newBankName: null })}
                >
                    {__('Change')}
                </Button>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-1.5">
            <BankCombobox
                key={version}
                value={bank?.id ?? null}
                defaultBank={bank ? { ...bank, user_id: null } : undefined}
                onValueChange={() => undefined}
                onBankChange={(picked) =>
                    onChange({
                        bank: picked
                            ? {
                                  id: picked.id,
                                  name: picked.name,
                                  logo: picked.logo,
                              }
                            : null,
                        newBankName: null,
                    })
                }
                onCreateCustomBank={(query) =>
                    onChange({ bank: null, newBankName: query.trim() || null })
                }
            />
            {!bank && suggestedName && (
                <button
                    type="button"
                    className="w-fit text-left text-[13px] text-muted-foreground underline underline-offset-4 hover:text-foreground"
                    onClick={() =>
                        onChange({ bank: null, newBankName: suggestedName })
                    }
                >
                    {__('Create «:name» as a new bank', {
                        name: suggestedName,
                    })}
                </button>
            )}
        </div>
    );
}
