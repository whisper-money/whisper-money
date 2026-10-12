import { useCurrentFrame } from 'remotion';
import { enter, rise } from '../animation';
import { useVideo } from '../context';
import { fonts } from '../fonts';

/** Scene heading: an eyebrow label, a big title and a muted subtitle. */
export function Caption({
    label,
    title,
    subtitle,
    delay = 0,
    align = 'center',
    titleSize = 76,
}: {
    label?: string;
    title: string;
    subtitle?: string;
    delay?: number;
    align?: 'center' | 'left';
    titleSize?: number;
}) {
    const frame = useCurrentFrame();
    const { theme } = useVideo();
    const labelIn = enter(frame, delay);
    const titleIn = enter(frame, delay + 4);
    const subtitleIn = enter(frame, delay + 12);

    return (
        <div
            style={{
                display: 'flex',
                flexDirection: 'column',
                alignItems: align === 'center' ? 'center' : 'flex-start',
                textAlign: align,
                gap: 18,
            }}
        >
            {label && (
                <span
                    style={{
                        opacity: labelIn,
                        transform: rise(labelIn, 12),
                        fontFamily: fonts.sans,
                        fontSize: 24,
                        fontWeight: 500,
                        color: theme.muted,
                        border: `1.5px solid ${theme.border}`,
                        borderRadius: 999,
                        padding: '8px 20px',
                    }}
                >
                    {label}
                </span>
            )}
            <h1
                style={{
                    margin: 0,
                    opacity: titleIn,
                    transform: rise(titleIn, 28),
                    fontFamily: fonts.serif,
                    fontStyle: 'italic',
                    fontWeight: 600,
                    fontSize: titleSize,
                    lineHeight: 1.08,
                    letterSpacing: -1,
                    color: theme.text,
                }}
            >
                {title}
            </h1>
            {subtitle && (
                <p
                    style={{
                        margin: 0,
                        opacity: subtitleIn,
                        transform: rise(subtitleIn, 20),
                        fontFamily: fonts.sans,
                        fontWeight: 500,
                        fontSize: 32,
                        color: theme.muted,
                    }}
                >
                    {subtitle}
                </p>
            )}
        </div>
    );
}
