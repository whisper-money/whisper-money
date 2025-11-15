import {
    DateFormat,
    type ColumnMapping,
    type ParsedRow,
    type ParsedTransaction,
} from '@/types/import';
import * as XLSX from 'xlsx';

function detectHeaderRow(columns: unknown[][]): number {
    if (!columns || columns.length === 0) {
        return 0;
    }

    const firstRowWithValue = columns.map((column) =>
        column.findIndex(
            (cell) =>
                cell !== undefined && cell !== null && String(cell).length > 1,
        ),
    );

    const percentages = [0.95, 0.75];

    for (const minPercentage of percentages) {
        const uniqueRows = [...new Set(firstRowWithValue)].sort(
            (a, b) => a - b,
        );

        for (const rowNumber of uniqueRows) {
            if (rowNumber === -1) continue;

            const columnsWithValues = columns.filter((column) => {
                return (
                    column[rowNumber] !== undefined &&
                    column[rowNumber] !== null &&
                    String(column[rowNumber]).length > 1
                );
            }).length;

            if (columnsWithValues / columns.length >= minPercentage) {
                return rowNumber;
            }
        }
    }

    return 0;
}

export async function parseFile(file: File): Promise<{
    headers: string[];
    data: ParsedRow[];
    columns: unknown[][];
    headerRowIndex: number;
}> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();

        reader.onload = (e) => {
            try {
                const data = e.target?.result;
                if (!data) {
                    reject(new Error('Failed to read file'));
                    return;
                }

                const workbook = XLSX.read(data, { type: 'binary' });
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];

                const jsonData = XLSX.utils.sheet_to_json(worksheet, {
                    header: 1,
                }) as unknown[][];

                if (jsonData.length === 0) {
                    reject(new Error('File is empty'));
                    return;
                }

                const maxColumns = Math.max(
                    ...jsonData.map((row) =>
                        Array.isArray(row) ? row.length : 0,
                    ),
                );
                const columns: unknown[][] = [];

                for (let colIndex = 0; colIndex < maxColumns; colIndex++) {
                    const columnData = jsonData.map((row) =>
                        Array.isArray(row) ? row[colIndex] : undefined,
                    );
                    columns.push(columnData);
                }

                const headerRowIndex = detectHeaderRow(columns);

                const letters = [
                    'A',
                    'B',
                    'C',
                    'D',
                    'E',
                    'F',
                    'G',
                    'H',
                    'I',
                    'J',
                    'K',
                    'L',
                    'M',
                    'N',
                    'O',
                    'P',
                    'Q',
                    'R',
                    'S',
                    'T',
                    'U',
                    'V',
                    'W',
                    'X',
                    'Y',
                    'Z',
                ];

                const headers = columns.map((column, index) => {
                    const headerValue = column[headerRowIndex];
                    const headerStr = String(headerValue || '').trim();

                    if (
                        headerStr &&
                        headerStr.length > 1 &&
                        isNaN(Number(headerStr))
                    ) {
                        return headerStr;
                    }

                    return letters[index] || `Column ${index + 1}`;
                });

                const dataRows = jsonData.slice(
                    headerRowIndex + 1,
                ) as unknown[][];

                const parsedData: ParsedRow[] = dataRows
                    .filter(
                        (row) =>
                            Array.isArray(row) &&
                            row.some(
                                (cell) =>
                                    cell !== null &&
                                    cell !== undefined &&
                                    cell !== '',
                            ),
                    )
                    .map((row) => {
                        const obj: ParsedRow = {};
                        headers.forEach((header, index) => {
                            if (header) {
                                const value = row[index];
                                obj[header] =
                                    value === null || value === undefined
                                        ? null
                                        : (value as string | number);
                            }
                        });
                        return obj;
                    });

                resolve({ headers, data: parsedData, columns, headerRowIndex });
            } catch {
                reject(new Error('Failed to parse file'));
            }
        };

        reader.onerror = () => {
            reject(new Error('Failed to read file'));
        };

        reader.readAsBinaryString(file);
    });
}

export function autoDetectDateFormat(
    data: ParsedRow[],
    dateColumnName: string,
): DateFormat | null {
    if (!data || data.length === 0 || !dateColumnName) {
        return null;
    }

    const formats = [
        DateFormat.YearMonthDay,
        DateFormat.DayMonthYear,
        DateFormat.MonthDayYear,
    ];
    const sampleSize = Math.min(10, data.length);
    const scores: Record<DateFormat, number> = {
        [DateFormat.YearMonthDay]: 0,
        [DateFormat.DayMonthYear]: 0,
        [DateFormat.MonthDayYear]: 0,
    };

    for (let i = 0; i < sampleSize; i++) {
        const dateValue = data[i][dateColumnName];
        if (!dateValue) continue;

        for (const format of formats) {
            const parsedDate = parseDate(dateValue as string | number, format);
            if (parsedDate) {
                scores[format]++;
            }
        }
    }

    const maxScore = Math.max(...Object.values(scores));

    if (maxScore === 0) {
        return null;
    }

    const bestFormat = formats.find((format) => scores[format] === maxScore);

    if (maxScore >= sampleSize * 0.8) {
        return bestFormat || null;
    }

    return null;
}

export function autoDetectColumns(headers: string[]): ColumnMapping {
    const mapping: ColumnMapping = {
        transaction_date: null,
        description: null,
        amount: null,
        balance: null,
    };

    if (!headers || headers.length === 0) {
        return mapping;
    }

    const lowerHeaders = headers.map((h) => {
        if (h === null || h === undefined) {
            return '';
        }
        return String(h).toLowerCase();
    });

    const datePatterns = [
        'date',
        'transaction date',
        'fecha',
        'transaction_date',
        'trans date',
        'trans_date',
        'f. valor',
    ];
    const descriptionPatterns = [
        'description',
        'desc',
        'descripcion',
        'concept',
        'concepto',
        'details',
        'detalles',
        'memo',
        'descripción',
    ];
    const amountPatterns = [
        'amount',
        'monto',
        'value',
        'valor',
        'total',
        'importe',
        'quantity',
        'cantidad',
    ];
    const balancePatterns = [
        'balance',
        'saldo',
        'current balance',
        'available balance',
        'saldo actual',
        'saldo disponible',
    ];

    for (let i = 0; i < lowerHeaders.length; i++) {
        const header = lowerHeaders[i];
        const originalHeader = headers[i];

        if (!header || typeof header !== 'string') {
            continue;
        }

        if (
            !mapping.transaction_date &&
            datePatterns.some((p) => header.includes(p))
        ) {
            mapping.transaction_date = originalHeader;
        }

        if (
            !mapping.description &&
            descriptionPatterns.some((p) => header.includes(p))
        ) {
            mapping.description = originalHeader;
        }

        if (!mapping.amount && amountPatterns.some((p) => header.includes(p))) {
            mapping.amount = originalHeader;
        }

        if (!mapping.balance && balancePatterns.some((p) => header.includes(p))) {
            mapping.balance = originalHeader;
        }
    }

    return mapping;
}

export function parseDate(
    dateStr: string | number,
    format: DateFormat,
): Date | null {
    if (!dateStr) {
        return null;
    }

    if (typeof dateStr === 'number') {
        const excelDate = XLSX.SSF.parse_date_code(dateStr);
        if (excelDate) {
            return new Date(excelDate.y, excelDate.m - 1, excelDate.d);
        }
    }

    let str = String(dateStr).trim();
    str = str
        .replace(/\//g, '-')
        .replace(/\./g, '-')
        .replace(/[^\d-]/g, '');

    let year: number | undefined,
        month: number | undefined,
        day: number | undefined;

    if (str.length === 5) {
        const dateRegex = /^(\d{1,2})-(\d{1,2})$/;
        const dateArray = dateRegex.exec(str);
        if (dateArray) {
            month = Number(
                dateArray[format === DateFormat.DayMonthYear ? 2 : 1],
            );
            day = Number(dateArray[format === DateFormat.DayMonthYear ? 1 : 2]);
        }
    } else {
        const parts = str.split('-').filter((p) => p.length > 0);

        if (parts.length === 3) {
            switch (format) {
                case DateFormat.YearMonthDay:
                    [year, month, day] = parts.map(Number);
                    break;
                case DateFormat.MonthDayYear:
                    [month, day, year] = parts.map(Number);
                    break;
                case DateFormat.DayMonthYear:
                    [day, month, year] = parts.map(Number);
                    break;
            }
        } else if (parts.length === 2) {
            month = Number(parts[format === DateFormat.DayMonthYear ? 1 : 0]);
            day = Number(parts[format === DateFormat.DayMonthYear ? 0 : 1]);
        }
    }

    if (year === undefined) {
        year = new Date().getFullYear();
    }

    if (year < 100) {
        year += year < 50 ? 2000 : 1900;
    }

    if (year === undefined || month === undefined || day === undefined) {
        return null;
    }

    const date = new Date(year, month - 1, day);

    if (
        isNaN(date.getTime()) ||
        date.getFullYear() !== year ||
        date.getMonth() !== month - 1 ||
        date.getDate() !== day
    ) {
        return null;
    }

    return date;
}

export function parseAmount(amountStr: string | number): number | null {
    if (typeof amountStr === 'number') {
        return amountStr;
    }

    if (!amountStr) {
        return null;
    }

    let str = String(amountStr).trim();

    const dotPos = str.lastIndexOf('.');
    const commaPos = str.lastIndexOf(',');

    const decimalSep =
        dotPos > commaPos && dotPos !== -1
            ? dotPos
            : commaPos > dotPos && commaPos !== -1
              ? commaPos
              : -1;

    if (decimalSep !== -1) {
        const integerPart = str.substring(0, decimalSep).replace(/[^\d]/g, '');
        const decimalPart = str.substring(decimalSep + 1);
        str = integerPart + '.' + decimalPart;
    } else {
        str = str.replace(/[^\d]/g, '');
    }

    const amount = parseFloat(str);

    if (isNaN(amount)) {
        return null;
    }

    return amount;
}

export function validateTransaction(
    row: ParsedRow,
    mapping: ColumnMapping,
    dateFormat: DateFormat,
): { isValid: boolean; errors: string[] } {
    const errors: string[] = [];

    if (!mapping.transaction_date || !row[mapping.transaction_date]) {
        errors.push('Missing transaction date');
    } else {
        const date = parseDate(
            row[mapping.transaction_date] as string | number,
            dateFormat,
        );
        if (!date) {
            errors.push('Invalid date format');
        }
    }

    if (!mapping.description || !row[mapping.description]) {
        errors.push('Missing description');
    }

    if (
        !mapping.amount ||
        row[mapping.amount] === null ||
        row[mapping.amount] === undefined
    ) {
        errors.push('Missing amount');
    } else {
        const amount = parseAmount(row[mapping.amount] as string | number);
        if (amount === null) {
            errors.push('Invalid amount format');
        }
    }

    return {
        isValid: errors.length === 0,
        errors,
    };
}

export function convertRowsToTransactions(
    rows: ParsedRow[],
    mapping: ColumnMapping,
    dateFormat: DateFormat,
): ParsedTransaction[] {
    const results: ParsedTransaction[] = [];

    for (const row of rows) {
        const validation = validateTransaction(row, mapping, dateFormat);

        if (!validation.isValid) {
            continue;
        }

        const date = parseDate(
            row[mapping.transaction_date!] as string | number,
            dateFormat,
        );
        const amount = parseAmount(row[mapping.amount!] as string | number);
        const description = String(row[mapping.description!] || '').trim();

        if (!date || amount === null || !description) {
            continue;
        }

        const formattedDate = date.toISOString().split('T')[0];

        let balance: number | null = null;
        if (mapping.balance && row[mapping.balance]) {
            const parsedBalance = parseAmount(row[mapping.balance] as string | number);
            if (parsedBalance !== null) {
                balance = Math.round(parsedBalance * 100);
            }
        }

        results.push({
            transaction_date: formattedDate,
            description,
            amount: Math.round(amount * 100),
            balance,
            validationErrors: [],
        });
    }

    return results;
}
