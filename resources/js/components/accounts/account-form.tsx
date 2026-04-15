import InputError from '@/components/input-error';
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
import { SharedData } from '@/types';
import {
    ACCOUNT_TYPES,
    AREA_UNITS,
    Account,
    CurrencyOption,
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
import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
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

export interface LoanFormData {
    annualInterestRate: string;
    loanTermMonths: string;
    startDate: string;
    originalAmount: number;
    linkedRealEstateAccountId: string | null;
}

export interface AccountFormData {
    displayName: string;
    bankId: string | null;
    type: AccountType | null;
    currencyCode: CurrencyCode | null;
    customBank: CustomBankData | null;
    balance: number | null;
    realEstate: RealEstateFormData | null;
    loan: LoanFormData | null;
}

interface AccountFormProps {
    initialValues?: {
        displayName: string;
        bank: Bank | null;
        type: AccountType;
        currencyCode: CurrencyCode;
        loan?: LoanFormData | null;
        realEstate?: RealEstateFormData | null;
    };
    forceAccountType?: AccountType;
    hiddenAccountTypes?: AccountType[];
    availableLoanAccounts?: Account[];
    usePrimaryCurrenciesOnly?: boolean;
    onChange: (data: AccountFormData) => void;
    errors?: Record<string, string>;
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

const initialLoanData: LoanFormData = {
    annualInterestRate: '',
    loanTermMonths: '',
    startDate: '',
    originalAmount: 0,
    linkedRealEstateAccountId: null,
};

export function AccountForm({
    initialValues,
    forceAccountType,
    hiddenAccountTypes = [],
    availableLoanAccounts = [],
    usePrimaryCurrenciesOnly = false,
    onChange,
    errors = {},
}: AccountFormProps) {
    const { currencies } = usePage<SharedData>().props;
    const currencyOptions = usePrimaryCurrenciesOnly
        ? currencies.profile
        : currencies.accounts;
    const [displayName, setDisplayName] = useState(
        initialValues?.displayName ?? '',
    );
    const [selectedBankId, setSelectedBankId] = useState<string | null>(
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
        initialValues?.realEstate ?? initialRealEstateData,
    );
    const [loanData, setLoanData] = useState<LoanFormData>(
        initialValues?.loan ?? initialLoanData,
    );
    const isRevaluationManuallySet = useRef(false);

    const showBalanceField =
        selectedType !== null && BALANCE_ACCOUNT_TYPES.includes(selectedType);
    const isRealEstate = selectedType === 'real_estate';
    const isLoan = selectedType === 'loan';
    const availableRealEstateAccounts = availableLoanAccounts.filter(
        (account) =>
            account.type === 'real_estate' &&
            (account.linked_loan_account_id === null ||
                account.linked_loan_account_id === undefined),
    );

    // Auto-calculate revaluation % (CAGR) when purchase data and current value are provided
    useEffect(() => {
        if (!isRealEstate || isRevaluationManuallySet.current) {
            return;
        }

        const purchasePrice = realEstateData.purchasePrice;
        const purchaseDate = realEstateData.purchaseDate;
        const currentValue = balance;

        if (
            !purchasePrice ||
            purchasePrice <= 0 ||
            !purchaseDate ||
            !currentValue ||
            currentValue <= 0
        ) {
            return;
        }

        const purchaseDateObj = new Date(purchaseDate);
        const today = new Date();
        const diffMs = today.getTime() - purchaseDateObj.getTime();
        const years = diffMs / (365.25 * 24 * 60 * 60 * 1000);

        if (years <= 0) {
            return;
        }

        const cagr =
            (Math.pow(currentValue / purchasePrice, 1 / years) - 1) * 100;
        const clampedCagr = Math.max(-100, Math.min(100, cagr));
        const rounded = Math.round(clampedCagr * 100) / 100;

        setRealEstateData((prev) => ({
            ...prev,
            revaluationPercentage: String(rounded),
        }));
    }, [
        isRealEstate,
        realEstateData.purchasePrice,
        realEstateData.purchaseDate,
        balance,
    ]);

    useEffect(() => {
        onChange({
            displayName,
            bankId: isCreatingCustomBank ? null : selectedBankId,
            type: selectedType,
            currencyCode: selectedCurrency,
            customBank: isCreatingCustomBank ? customBankData : null,
            balance: showBalanceField ? balance : null,
            realEstate: isRealEstate ? realEstateData : null,
            loan: isLoan ? loanData : null,
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
        isLoan,
        realEstateData,
        loanData,
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
            setRealEstateData(
                initialValues.realEstate ?? initialRealEstateData,
            );
            setLoanData(initialValues.loan ?? initialLoanData);
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
                            {currencyOptions.map((currency: CurrencyOption) => (
                                <SelectItem
                                    key={currency.code}
                                    value={currency.code}
                                >
                                    {currency.code} - {currency.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </div>

            {showBalanceField && selectedCurrency && !initialValues && (
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

            {isLoan && selectedCurrency && (
                <>
                    <div className="space-y-2">
                        <Label htmlFor="annual_interest_rate">
                            {__('Annual Interest Rate (%)')}
                        </Label>
                        <Input
                            id="annual_interest_rate"
                            type="number"
                            className="mt-1"
                            value={loanData.annualInterestRate}
                            onChange={(e) =>
                                setLoanData((prev) => ({
                                    ...prev,
                                    annualInterestRate: e.target.value,
                                }))
                            }
                            placeholder="3.5"
                            min="0"
                            max="100"
                            step="0.001"
                        />
                        <InputError message={errors.annual_interest_rate} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="loan_term_months">
                            {__('Loan Term (months)')}
                        </Label>
                        <Input
                            id="loan_term_months"
                            type="number"
                            className="mt-1"
                            value={loanData.loanTermMonths}
                            onChange={(e) =>
                                setLoanData((prev) => ({
                                    ...prev,
                                    loanTermMonths: e.target.value,
                                }))
                            }
                            placeholder="360"
                            min="1"
                            max="600"
                        />
                        <InputError message={errors.loan_term_months} />
                        <p className="pl-1 text-xs text-muted-foreground">
                            {__('e.g. 360 for a 30-year mortgage')}
                        </p>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="loan_start_date">
                            {__('Loan Start Date')}
                        </Label>
                        <Input
                            id="loan_start_date"
                            type="date"
                            className="mt-1"
                            value={loanData.startDate}
                            onChange={(e) =>
                                setLoanData((prev) => ({
                                    ...prev,
                                    startDate: e.target.value,
                                }))
                            }
                        />
                        <InputError message={errors.loan_start_date} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="original_amount">
                            {__('Original Loan Amount')}
                        </Label>
                        <div className="mt-1">
                            <AmountInput
                                id="original_amount"
                                value={loanData.originalAmount}
                                onChange={(value) =>
                                    setLoanData((prev) => ({
                                        ...prev,
                                        originalAmount: value,
                                    }))
                                }
                                currencyCode={selectedCurrency}
                            />
                        </div>
                        <InputError message={errors.original_amount} />
                    </div>

                    {availableRealEstateAccounts.length > 0 && (
                        <div className="space-y-2">
                            <Label htmlFor="linked_real_estate_account_id">
                                {__('Linked Property')}
                            </Label>
                            <p className="text-xs text-muted-foreground">
                                {__(
                                    'Optionally link this loan to an existing unlinked real-estate account.',
                                )}
                            </p>
                            <div className="mt-1">
                                <Select
                                    name="linked_real_estate_account_id"
                                    value={
                                        loanData.linkedRealEstateAccountId ??
                                        'none'
                                    }
                                    onValueChange={(value) =>
                                        setLoanData((prev) => ({
                                            ...prev,
                                            linkedRealEstateAccountId:
                                                value === 'none' ? null : value,
                                        }))
                                    }
                                >
                                    <SelectTrigger name="linked_real_estate_account_id">
                                        <SelectValue
                                            placeholder={__(
                                                'No linked property',
                                            )}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            {__('No linked property')}
                                        </SelectItem>
                                        {availableRealEstateAccounts.map(
                                            (realEstateAccount) => (
                                                <SelectItem
                                                    key={realEstateAccount.id}
                                                    value={realEstateAccount.id}
                                                >
                                                    {realEstateAccount.name}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                            <InputError
                                message={errors.linked_real_estate_account_id}
                            />
                        </div>
                    )}

                    <p className="pl-1 text-xs text-muted-foreground">
                        {__(
                            'Optional. Provide loan details to automatically project your owed amount over time.',
                        )}
                    </p>
                </>
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
                            onChange={(value) => {
                                isRevaluationManuallySet.current = false;
                                setRealEstateData((prev) => ({
                                    ...prev,
                                    purchasePrice: value,
                                }));
                            }}
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
                            onChange={(e) => {
                                isRevaluationManuallySet.current = false;
                                setRealEstateData((prev) => ({
                                    ...prev,
                                    purchaseDate: e.target.value,
                                }));
                            }}
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
                            onChange={(e) => {
                                isRevaluationManuallySet.current = true;
                                setRealEstateData((prev) => ({
                                    ...prev,
                                    revaluationPercentage: e.target.value,
                                }));
                            }}
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
