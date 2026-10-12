import { TrendingUpIcon } from 'lucide-react';
import { interpolate, useCurrentFrame } from 'remotion';
import { enter, progress } from '../animation';
import { AppWindow } from '../components/AppWindow';
import { SceneLayout } from '../components/SceneLayout';
import { useVideo } from '../context';
import { fonts } from '../fonts';
import { formatEuros } from '../format';

/** Stacked net worth bars per month: [cash, savings, investments, other]. */
const BARS = [
    [36, 22, 8, 4], [33, 24, 9, 4], [36, 25, 10, 4], [37, 26, 11, 4],
    [40, 26, 12, 4], [42, 27, 12, 5], [44, 28, 14, 5], [46, 29, 15, 5],
    [48, 30, 16, 5], [50, 31, 18, 6], [49, 32, 19, 6], [52, 33, 20, 6],
];
const MAX_BAR = 115;
const CHART_HEIGHT = 300;
const NET_WORTH = 87031;

const ACCOUNTS = [
    { key: 'daily', balance: 1214.8, color: '#ea580c', points: [12, 14, 13, 22, 10, 6, 11, 9, 24, 15] },
    { key: 'payroll', balance: 4763.34, color: '#1d4ed8', points: [16, 20, 4, 18, 18, 17, 22, 16, 21, 14] },
    { key: 'savings', balance: 23412.0, color: '#0f766e', points: [4, 6, 7, 9, 10, 13, 15, 16, 19, 22] },
] as const;

function sparklinePath(points: readonly number[], width: number, height: number): string {
    const step = width / (points.length - 1);

    return points
        .map((point, index) => `${index === 0 ? 'M' : 'L'} ${index * step} ${height - (point / 26) * height}`)
        .join(' ');
}

export function Dashboard() {
    const frame = useCurrentFrame();
    const { theme, copy, locale } = useVideo();
    const counter = progress(frame, 16, 70);

    return (
        <SceneLayout title={copy.dashboard.title} subtitle={copy.dashboard.subtitle}>
            <AppWindow active="dashboard" title={copy.dashboard.pageTitle} width={1560} height={690}>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 22, height: '100%' }}>
                    <div
                        style={{
                            display: 'flex',
                            gap: 48,
                            padding: 28,
                            borderRadius: 18,
                            border: `1.5px solid ${theme.border}`,
                        }}
                    >
                        <div style={{ width: 340, display: 'flex', flexDirection: 'column', gap: 10 }}>
                            <span style={{ fontSize: 22, fontWeight: 500 }}>{copy.dashboard.netWorth}</span>
                            <span style={{ fontSize: 64, fontWeight: 600, letterSpacing: -1 }}>
                                {formatEuros(Math.round(NET_WORTH * counter), locale, 0)}
                            </span>
                            <span
                                style={{
                                    opacity: enter(frame, 60),
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: 10,
                                    fontSize: 21,
                                    color: theme.muted,
                                }}
                            >
                                {copy.dashboard.change} {copy.dashboard.thisMonth}
                                <TrendingUpIcon size={22} color={theme.positive} />
                            </span>
                        </div>
                        <div style={{ flex: 1, display: 'flex', alignItems: 'flex-end', gap: 18 }}>
                            {BARS.map((segments, index) => {
                                const grow = progress(frame, 18 + index * 3, 48 + index * 3);

                                return (
                                    <div
                                        key={copy.dashboard.months[index]}
                                        style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 10 }}
                                    >
                                        <div
                                            style={{
                                                width: '100%',
                                                height: CHART_HEIGHT,
                                                display: 'flex',
                                                flexDirection: 'column-reverse',
                                            }}
                                        >
                                            {segments.map((segment, segmentIndex) => (
                                                <div
                                                    key={segmentIndex}
                                                    style={{
                                                        height: (segment / MAX_BAR) * CHART_HEIGHT * grow,
                                                        background: theme.chart[3 - segmentIndex],
                                                        borderTopLeftRadius: segmentIndex === 3 ? 6 : 0,
                                                        borderTopRightRadius: segmentIndex === 3 ? 6 : 0,
                                                        borderTop: segmentIndex === 0 ? 'none' : `2px solid ${theme.surface}`,
                                                    }}
                                                />
                                            ))}
                                        </div>
                                        <span style={{ fontSize: 16, color: theme.muted }}>{copy.dashboard.months[index]}</span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                    <div style={{ display: 'flex', gap: 22, flex: 1 }}>
                        {ACCOUNTS.map((account, index) => {
                            const cardIn = enter(frame, 44 + index * 8);
                            const draw = progress(frame, 54 + index * 8, 90 + index * 8);

                            return (
                                <div
                                    key={account.key}
                                    style={{
                                        flex: 1,
                                        opacity: cardIn,
                                        transform: `translateY(${(1 - cardIn) * 30}px)`,
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'space-between',
                                        padding: '0 28px',
                                        borderRadius: 18,
                                        border: `1.5px solid ${theme.border}`,
                                    }}
                                >
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                                        <span style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 21, fontWeight: 500 }}>
                                            <span style={{ width: 22, height: 22, borderRadius: 999, background: account.color }} />
                                            {copy.accounts[account.key].name}
                                        </span>
                                        <span style={{ fontFamily: fonts.sans, fontSize: 34, fontWeight: 600 }}>
                                            {formatEuros(account.balance * interpolate(draw, [0, 1], [0.6, 1]), locale)}
                                        </span>
                                    </div>
                                    <svg width={170} height={64} style={{ overflow: 'visible' }}>
                                        <path
                                            d={sparklinePath(account.points, 170, 64)}
                                            pathLength={1}
                                            fill="none"
                                            stroke={theme.text}
                                            strokeWidth={2.5}
                                            strokeLinejoin="round"
                                            strokeDasharray="1 1"
                                            strokeDashoffset={1 - draw}
                                        />
                                    </svg>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </AppWindow>
        </SceneLayout>
    );
}
