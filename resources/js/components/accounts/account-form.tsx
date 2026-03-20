import { AmountInput } from '@/components/ui/amount-input';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    ACCOUNT_TYPES,
    CURRENCY_OPTIONS,
    balanceTermCapitalized,
    formatAccountType,
    type AccountType,
    type Bank,
    type CurrencyCode,
} from '@/types/account';
import { __ } from '@/utils/i18n';
import { useCallback, useEffect, useState } from 'react';
import { BankCombobox } from './bank-combobox';
import { CustomBankData, CustomBankForm } from './custom-bank-form';

const BALANCE_ACCOUNT_TYPES: AccountType[] = [
    'investment',
    'loan',
    'retirement',
    'savings',
];

export interface AccountFormData {
    displayName: string;
    bankId: number | null;
    type: AccountType | null;
    currencyCode: CurrencyCode | null;
    customBank: CustomBankData | null;
    balance: number | null;
}

interface AccountFormProps {
    initialValues?: {
        displayName: string;
        bank: Bank;
        type: AccountType;
        currencyCode: CurrencyCode;
    };
    forceAccountType?: AccountType;
    onChange: (data: AccountFormData) => void;
}

const initialCustomBankData: CustomBankData = {
    name: '',
    logo: null,
    logoPreview: null,
};

export function AccountForm({
    initialValues,
    forceAccountType,
    onChange,
}: AccountFormProps) {
    const [displayName, setDisplayName] = useState(
        initialValues?.displayName ?? '',
    );
    const [selectedBankId, setSelectedBankId] = useState<number | null>(
        initialValues?.bank.id ?? null,
    );
    const [selectedType, setSelectedType] = useState<AccountType | null>(
        initialValues?.type ?? forceAccountType ?? null,
    );
    const [selectedCurrency, setSelectedCurrency] =
        useState<CurrencyCode | null>(initialValues?.currencyCode ?? null);
    const [isCreatingCustomBank, setIsCreatingCustomBank] = useState(false);
    const [customBankData, setCustomBankData] = useState<CustomBankData>(
        initialCustomBankData,
    );
    const [balance, setBalance] = useState<number | null>(null);

    const showBalanceField =
        selectedType !== null && BALANCE_ACCOUNT_TYPES.includes(selectedType);

    useEffect(() => {
        onChange({
            displayName,
            bankId: isCreatingCustomBank ? null : selectedBankId,
            type: selectedType,
            currencyCode: selectedCurrency,
            customBank: isCreatingCustomBank ? customBankData : null,
            balance: showBalanceField ? balance : null,
        });
    }, [
        displayName,
        selectedBankId,
        selectedType,
        selectedCurrency,
        isCreatingCustomBank,
        customBankData,
        balance,
        showBalanceField,
        onChange,
    ]);

    useEffect(() => {
        if (initialValues) {
            setDisplayName(initialValues.displayName);
            setSelectedBankId(initialValues.bank.id);
            setSelectedType(initialValues.type);
            setSelectedCurrency(initialValues.currencyCode);
            setIsCreatingCustomBank(false);
            setCustomBankData(initialCustomBankData);
        }
    }, [initialValues]);

    const handleCreateCustomBank = useCallback((searchQuery: string) => {
        setIsCreatingCustomBank(true);
        setCustomBankData({
            name: searchQuery,
            logo: null,
            logoPreview: null,
        });
        setSelectedBankId(null);
    }, []);

    const handleCancelCustomBank = useCallback(() => {
        setIsCreatingCustomBank(false);
        setCustomBankData(initialCustomBankData);
    }, []);

    return (
        <>
            <div className="space-y-2">
                <Label htmlFor="display_name">{__('Name')}</Label>
                <Input
                    id="display_name"
                    className="mt-1"
                    name="display_name"
                    value={displayName}
                    onChange={(e) => setDisplayName(e.target.value)}
                    placeholder={__('Account name')}
                    required
                />
            </div>

            <div className="space-y-2">
                <Label htmlFor="bank_id">{__('Bank')}</Label>
                <div className="mt-1">
                    {isCreatingCustomBank ? (
                        <CustomBankForm
                            defaultName={customBankData.name}
                            value={customBankData}
                            onChange={setCustomBankData}
                            onCancel={handleCancelCustomBank}
                        />
                    ) : (
                        <>
                            <input
                                type="hidden"
                                name="bank_id"
                                value={selectedBankId ?? ''}
                                required
                            />

                            <BankCombobox
                                value={selectedBankId}
                                onValueChange={setSelectedBankId}
                                defaultBank={initialValues?.bank}
                                onCreateCustomBank={handleCreateCustomBank}
                            />
                        </>
                    )}
                </div>
            </div>

            <div className="space-y-2">
                <Label htmlFor="type">{__('Account Type')}</Label>
                <div className="mt-1">
                    <Select
                        name="type"
                        value={selectedType ?? undefined}
                        disabled={!!forceAccountType}
                        onValueChange={(value) =>
                            setSelectedType(value as AccountType)
                        }
                        required
                    >
                        <SelectTrigger name="type">
                            <SelectValue
                                placeholder={__('Select account type')}
                            />
                        </SelectTrigger>
                        <SelectContent>
                            {ACCOUNT_TYPES.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {formatAccountType(type)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                {(selectedType === 'investment' ||
                    selectedType === 'retirement') && (
                    <p className="pl-1 text-xs text-muted-foreground">
                        {__(
                            "This account type is for balance tracking only and\n                        doesn't support transactions.",
                        )}
                    </p>
                )}
            </div>

            <div className="space-y-2">
                <Label htmlFor="currency_code">{__('Currency')}</Label>
                <div className="mt-1">
                    <Select
                        name="currency_code"
                        value={selectedCurrency ?? undefined}
                        onValueChange={(value) =>
                            setSelectedCurrency(value as CurrencyCode)
                        }
                        required
                    >
                        <SelectTrigger name="currency_code">
                            <SelectValue placeholder={__('Select currency')} />
                        </SelectTrigger>
                        <SelectContent>
                            {CURRENCY_OPTIONS.map((currency) => (
                                <SelectItem key={currency} value={currency}>
                                    {currency}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </div>

            {showBalanceField && selectedCurrency && (
                <div className="space-y-2">
                    <Label htmlFor="balance">
                        {balanceTermCapitalized(selectedType!)}
                    </Label>
                    <div className="mt-1">
                        <AmountInput
                            id="balance"
                            value={balance ?? 0}
                            onChange={setBalance}
                            currencyCode={selectedCurrency}
                        />
                    </div>
                    <p className="pl-1 text-xs text-muted-foreground">
                        {__(
                            'Optional. Set the current balance for this account.',
                        )}
                    </p>
                </div>
            )}
        </>
    );
}
