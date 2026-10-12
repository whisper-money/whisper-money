import { useVideo } from '../context';
import { fonts } from '../fonts';
import { formatEuros } from '../format';

/** Transaction amount, with the app's green pill for income. */
export function Amount({ value, size = 22 }: { value: number; size?: number }) {
    const { theme, locale } = useVideo();
    const isIncome = value > 0;

    return (
        <span
            style={{
                fontFamily: fonts.mono,
                fontSize: size,
                fontWeight: 500,
                color: isIncome ? theme.positive : theme.text,
                background: isIncome ? theme.positiveSoft : 'transparent',
                borderRadius: 6,
                padding: isIncome ? '2px 8px' : 0,
            }}
        >
            {formatEuros(value, locale)}
        </span>
    );
}
