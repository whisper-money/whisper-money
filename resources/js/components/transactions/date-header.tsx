import { format, getYear, parseISO } from 'date-fns';

export interface DateHeaderProps {
    date: string;
    colSpan: number;
}

export function DateHeader({ date, colSpan }: DateHeaderProps) {
    const parsedDate = parseISO(date);
    const currentYear = getYear(new Date());
    const transactionYear = getYear(parsedDate);
    const formatString =
        transactionYear === currentYear ? 'MMM d' : 'MMM d, yy';

    return (
        <tr className="bg-muted/50">
            <td
                colSpan={colSpan}
                className="px-4 py-2 text-sm font-normal text-muted-foreground"
            >
                {format(parsedDate, formatString)}
            </td>
        </tr>
    );
}
