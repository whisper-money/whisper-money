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
import { Textarea } from '@/components/ui/textarea';
import {
    ACCOUNT_TYPES,
    AREA_UNITS,
    Account,
    CURRENCY_OPTIONS,
    PROPERTY_TYPES,
    balanceTermCapitalized,
    formatAccountType,
    formatAreaUnit,
    formatPropertyType,
    type AccountType,
    type AreaUnit,
    type Bank,
    type CurrencyCode,
    type PropertyType,
} from '@/types/account';
import { __ } from '@/utils/i18n';
import { useCallback, useEffect, useState } from 'react';
import { BankCombobox } from './bank-combobox';
import { CustomBankData, CustomBankForm } from './custom-bank-form';

const BALANCE_ACCOUNT_TYPES: AccountType[] = [
    'investment',
    'loan',
    'real_estate',
    'retirement',
    'savings',
];

export interface RealEstateFormData {
    propertyType: PropertyType | null;
    address: string;
    purchasePrice: number;
    purchaseDate: string;
    areaValue: string;
    areaUnit: AreaUnit | null;
    linkedLoanAccountId: string | null;
    notes: string;
    revaluationPercentage: string;
}

export interface AccountFormData {
    displayName: string;
    bankId: number | null;
    type: AccountType | null;
    currencyCode: CurrencyCode | null;
    customBank: CustomBankData | null;
    balance: number | null;
    realEstate: RealEstateFormData | null;
}

interface AccountFormProps {
    initialValues?: {
        displayName: string;
        bank: Bank | null;
        type: AccountType;
        currencyCode: CurrencyCode;
    };
    forceAccountType?: AccountType;
    hiddenAccountTypes?: AccountType[];
    availableLoanAccounts?: Account[];
    onChange: (data: AccountFormData) => void;
}

const initialCustomBankData: CustomBankData = {
    name: '',
    logo: null,
    logoPreview: null,
};

const initialRealEstateData: RealEstateFormData = {
    propertyType: null,
    address: '',
    purchasePrice: 0,
    purchaseDate: '',
    areaValue: '',
    areaUnit: null,
    linkedLoanAccountId: null,
    notes: '',
    revaluationPercentage: '',
};

export function AccountForm({
    initialValues,
    forceAccountType,
    hiddenAccountTypes = [],
    availableLoanAccounts = [],
    onChange,
}: AccountFormProps) {
    const [displayName, setDisplayName] = useState(
        initialValues?.displayName ?? '',
    );
    const [selectedBankId, setSelectedBankId] = useState<number | null>(
        initialValues?.bank?.id ?? null,
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
    const [realEstateData, setRealEstateData] = useState<RealEstateFormData>(
        initialRealEstateData,
    );

    const showBalanceField =
        selectedType !== null && BALANCE_ACCOUNT_TYPES.includes(selectedType);
    const isRealEstate = selectedType === 'real_estate';

    useEffect(() => {
        onChange({
            displayName,
            bankId: isCreatingCustomBank ? null : selectedBankId,
            type: selectedType,
            currencyCode: selectedCurrency,
            customBank: isCreatingCustomBank ? customBankData : null,
            balance: showBalanceField ? balance : null,
            realEstate: isRealEstate ? realEstateData : null,
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
        isRealEstate,
        realEstateData,
        onChange,
    ]);

    useEffect(() => {
        if (initialValues) {
            setDisplayName(initialValues.displayName);
            setSelectedBankId(initialValues.bank?.id ?? null);
            setSelectedType(initialValues.type);
            setSelectedCurrency(initialValues.currencyCode);
            setIsCreatingCustomBank(false);
            setCustomBankData(initialCustomBankData);
            setRealEstateData(initialRealEstateData);
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
                <Label htmlFor="type">{__('Account Type')}</Label>
                <div className="mt-1">
                    <Select
                        name="type"
                        value={selectedType ?? undefined}
                        disabled={!!forceAccountType}
                        onValueChange={(value) => {
                            const newType = value as AccountType;
                            setSelectedType(newType);
                            if (newType === 'real_estate') {
                                setSelectedBankId(null);
                                setIsCreatingCustomBank(false);
                                setCustomBankData(initialCustomBankData);
                            }
                        }}
                        required
                    >
                        <SelectTrigger name="type">
                            <SelectValue
                                placeholder={__('Select account type')}
                            />
                        </SelectTrigger>
                        <SelectContent>
                            {ACCOUNT_TYPES.filter(
                                (type) => !hiddenAccountTypes.includes(type),
                            ).map((type) => (
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
                {isRealEstate && (
                    <p className="pl-1 text-xs text-muted-foreground">
                        {__(
                            'Track your property market value over time. Transactions are not supported.',
                        )}
                    </p>
                )}
            </div>

            {!isRealEstate && (
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
                                    defaultBank={
                                        initialValues?.bank ?? undefined
                                    }
                                    onCreateCustomBank={handleCreateCustomBank}
                                />
                            </>
                        )}
                    </div>
                </div>
            )}

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

            {isRealEstate && (
                <>
                    <div className="space-y-2">
                        <Label htmlFor="property_type">
                            {__('Property Type')}
                        </Label>
                        <div className="mt-1">
                            <Select
                                name="property_type"
                                value={realEstateData.propertyType ?? undefined}
                                onValueChange={(value) =>
                                    setRealEstateData((prev) => ({
                                        ...prev,
                                        propertyType: value as PropertyType,
                                    }))
                                }
                                required
                            >
                                <SelectTrigger name="property_type">
                                    <SelectValue
                                        placeholder={__('Select property type')}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    {PROPERTY_TYPES.map((type) => (
                                        <SelectItem key={type} value={type}>
                                            {formatPropertyType(type)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="address">{__('Address')}</Label>
                        <Input
                            id="address"
                            className="mt-1"
                            name="address"
                            value={realEstateData.address}
                            onChange={(e) =>
                                setRealEstateData((prev) => ({
                                    ...prev,
                                    address: e.target.value,
                                }))
                            }
                            placeholder={__('Property address')}
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="purchase_price">
                            {__('Purchase Price')}
                        </Label>
                        <AmountInput
                            id="purchase_price"
                            className="mt-1"
                            value={realEstateData.purchasePrice}
                            onChange={(value) =>
                                setRealEstateData((prev) => ({
                                    ...prev,
                                    purchasePrice: value,
                                }))
                            }
                            currencyCode={selectedCurrency ?? 'USD'}
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="purchase_date">
                            {__('Purchase Date')}
                        </Label>
                        <Input
                            id="purchase_date"
                            type="date"
                            className="mt-1"
                            value={realEstateData.purchaseDate}
                            onChange={(e) =>
                                setRealEstateData((prev) => ({
                                    ...prev,
                                    purchaseDate: e.target.value,
                                }))
                            }
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-2">
                        <div className="space-y-2">
                            <Label htmlFor="area_value">{__('Area')}</Label>
                            <Input
                                id="area_value"
                                type="number"
                                className="mt-1"
                                value={realEstateData.areaValue}
                                onChange={(e) =>
                                    setRealEstateData((prev) => ({
                                        ...prev,
                                        areaValue: e.target.value,
                                    }))
                                }
                                placeholder="0"
                                min="0"
                                step="0.01"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="area_unit">{__('Unit')}</Label>
                            <div className="mt-1">
                                <Select
                                    name="area_unit"
                                    value={realEstateData.areaUnit ?? undefined}
                                    onValueChange={(value) =>
                                        setRealEstateData((prev) => ({
                                            ...prev,
                                            areaUnit: value as AreaUnit,
                                        }))
                                    }
                                >
                                    <SelectTrigger name="area_unit">
                                        <SelectValue
                                            placeholder={__('Select unit')}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {AREA_UNITS.map((unit) => (
                                            <SelectItem key={unit} value={unit}>
                                                {formatAreaUnit(unit)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </div>

                    {availableLoanAccounts.length > 0 && (
                        <div className="space-y-2">
                            <Label htmlFor="linked_loan_account_id">
                                {__('Linked Mortgage / Loan')}
                            </Label>
                            <p className="text-xs text-muted-foreground">
                                {__(
                                    'Link a loan account to track equity (market value minus owed amount).',
                                )}
                            </p>
                            <div className="mt-1">
                                <Select
                                    name="linked_loan_account_id"
                                    value={
                                        realEstateData.linkedLoanAccountId ??
                                        'none'
                                    }
                                    onValueChange={(value) =>
                                        setRealEstateData((prev) => ({
                                            ...prev,
                                            linkedLoanAccountId:
                                                value === 'none' ? null : value,
                                        }))
                                    }
                                >
                                    <SelectTrigger name="linked_loan_account_id">
                                        <SelectValue
                                            placeholder={__('No linked loan')}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            {__('No linked loan')}
                                        </SelectItem>
                                        {availableLoanAccounts.map((loan) => (
                                            <SelectItem
                                                key={loan.id}
                                                value={loan.id}
                                            >
                                                {loan.name}{' '}
                                                {loan.bank
                                                    ? `(${loan.bank.name})`
                                                    : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    )}

                    <div className="space-y-2">
                        <Label htmlFor="notes">{__('Notes')}</Label>
                        <Textarea
                            id="notes"
                            className="mt-1"
                            name="notes"
                            value={realEstateData.notes}
                            onChange={(e) =>
                                setRealEstateData((prev) => ({
                                    ...prev,
                                    notes: e.target.value,
                                }))
                            }
                            placeholder={__(
                                'Additional notes about this property',
                            )}
                            rows={3}
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="revaluation_percentage">
                            {__('Annual Revaluation (%)')}
                        </Label>
                        <Input
                            id="revaluation_percentage"
                            type="number"
                            className="mt-1"
                            value={realEstateData.revaluationPercentage}
                            onChange={(e) =>
                                setRealEstateData((prev) => ({
                                    ...prev,
                                    revaluationPercentage: e.target.value,
                                }))
                            }
                            placeholder="0.00"
                            min="-100"
                            max="100"
                            step="0.01"
                        />
                        <p className="pl-1 text-xs text-muted-foreground">
                            {__(
                                'Annual percentage applied monthly. Use negative values for depreciation.',
                            )}
                        </p>
                    </div>
                </>
            )}
        </>
    );
}
