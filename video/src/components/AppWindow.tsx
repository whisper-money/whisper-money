import { CreditCardIcon, LayoutGridIcon, ReceiptIcon, TargetIcon } from 'lucide-react';
import { type CSSProperties, type ReactNode } from 'react';
import { useVideo } from '../context';
import { fonts } from '../fonts';
import { Logo } from './Logo';

const NAV_ICONS = {
    dashboard: LayoutGridIcon,
    accounts: CreditCardIcon,
    transactions: ReceiptIcon,
    budgets: TargetIcon,
} as const;

export type NavItem = keyof typeof NAV_ICONS;

/** A simplified Whisper Money app shell: icon sidebar, top bar and page. */
export function AppWindow({
    active,
    title,
    width,
    height,
    style,
    children,
}: {
    active: NavItem;
    title: string;
    width: number;
    height: number;
    style?: CSSProperties;
    children: ReactNode;
}) {
    const { theme } = useVideo();

    return (
        <div
            style={{
                width,
                height,
                display: 'flex',
                borderRadius: 28,
                border: `1.5px solid ${theme.border}`,
                background: theme.background,
                boxShadow: theme.shadow,
                overflow: 'hidden',
                fontFamily: fonts.sans,
                color: theme.text,
                ...style,
            }}
        >
            <div
                style={{
                    width: 84,
                    display: 'flex',
                    flexDirection: 'column',
                    alignItems: 'center',
                    gap: 18,
                    paddingTop: 22,
                }}
            >
                <div
                    style={{
                        width: 46,
                        height: 46,
                        borderRadius: 12,
                        background: theme.text,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        marginBottom: 10,
                    }}
                >
                    <Logo size={28} color={theme.background} />
                </div>
                {(Object.keys(NAV_ICONS) as NavItem[]).map((item) => {
                    const Icon = NAV_ICONS[item];

                    return (
                        <div
                            key={item}
                            style={{
                                width: 46,
                                height: 46,
                                borderRadius: 12,
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                background: item === active ? theme.surfaceMuted : 'transparent',
                                color: item === active ? theme.text : theme.muted,
                            }}
                        >
                            <Icon size={24} strokeWidth={1.8} />
                        </div>
                    );
                })}
            </div>
            <div
                style={{
                    flex: 1,
                    margin: '12px 12px 12px 0',
                    borderRadius: 18,
                    background: theme.surface,
                    border: `1.5px solid ${theme.border}`,
                    display: 'flex',
                    flexDirection: 'column',
                    overflow: 'hidden',
                }}
            >
                <div
                    style={{
                        height: 64,
                        display: 'flex',
                        alignItems: 'center',
                        padding: '0 32px',
                        borderBottom: `1.5px solid ${theme.border}`,
                        fontSize: 22,
                        fontWeight: 500,
                    }}
                >
                    {title}
                </div>
                <div style={{ flex: 1, padding: 32, position: 'relative' }}>{children}</div>
            </div>
        </div>
    );
}
