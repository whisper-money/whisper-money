import { BellIcon, PlaneIcon } from 'lucide-react';
import { useCurrentFrame } from 'remotion';
import { enter, pop, progress } from '../animation';
import { AppWindow } from '../components/AppWindow';
import { SceneLayout } from '../components/SceneLayout';
import { useVideo } from '../context';
import { fonts } from '../fonts';
import { formatEuros } from '../format';

const RING_SIZE = 280;
const RING_STROKE = 22;
const ALERT_AT = 78;

export function Budgets() {
    const frame = useCurrentFrame();
    const { theme, copy, locale } = useVideo();
    const { goal } = copy.budgets;
    const goalFill = progress(frame, 30, 100);
    const radius = (RING_SIZE - RING_STROKE) / 2;
    const circumference = 2 * Math.PI * radius;
    const goalRatio = (goal.saved / goal.target) * goalFill;
    const alertIn = pop(frame, ALERT_AT);
    const overspent = copy.budgets.items.find((item) => item.spent > item.limit);

    return (
        <SceneLayout title={copy.budgets.title} subtitle={copy.budgets.subtitle}>
            <AppWindow active="budgets" title={copy.budgets.pageTitle} width={1560} height={690}>
                <div style={{ display: 'flex', gap: 28, height: '100%' }}>
                    <div
                        style={{
                            flex: 1,
                            display: 'flex',
                            flexDirection: 'column',
                            justifyContent: 'center',
                            gap: 44,
                            padding: '0 40px',
                            borderRadius: 18,
                            border: `1.5px solid ${theme.border}`,
                        }}
                    >
                        {copy.budgets.items.map((item, index) => {
                            const rowIn = enter(frame, 14 + index * 6);
                            const fill = progress(frame, 24 + index * 8, 84 + index * 8);
                            const ratio = (item.spent / item.limit) * fill;
                            const isOver = ratio > 1;
                            const barColor = isOver ? theme.negative : ratio > 0.85 ? theme.warning : theme.text;

                            return (
                                <div
                                    key={item.name}
                                    style={{ opacity: rowIn, transform: `translateY(${(1 - rowIn) * 20}px)`, display: 'flex', flexDirection: 'column', gap: 16 }}
                                >
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                                        <span style={{ fontSize: 28, fontWeight: 600 }}>{item.name}</span>
                                        <span style={{ fontFamily: fonts.mono, fontSize: 24, color: isOver ? theme.negative : theme.muted }}>
                                            {formatEuros(item.spent * fill, locale, 0)} {copy.budgets.spentOf} {formatEuros(item.limit, locale, 0)}
                                        </span>
                                    </div>
                                    <div style={{ height: 16, borderRadius: 999, background: theme.surfaceMuted, overflow: 'hidden' }}>
                                        <div
                                            style={{
                                                width: `${Math.min(ratio, 1) * 100}%`,
                                                height: '100%',
                                                borderRadius: 999,
                                                background: barColor,
                                            }}
                                        />
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                    <div
                        style={{
                            width: 520,
                            display: 'flex',
                            flexDirection: 'column',
                            alignItems: 'center',
                            justifyContent: 'center',
                            gap: 28,
                            borderRadius: 18,
                            border: `1.5px solid ${theme.border}`,
                            opacity: enter(frame, 20),
                        }}
                    >
                        <div style={{ position: 'relative', width: RING_SIZE, height: RING_SIZE }}>
                            <svg width={RING_SIZE} height={RING_SIZE} style={{ transform: 'rotate(-90deg)' }}>
                                <circle cx={RING_SIZE / 2} cy={RING_SIZE / 2} r={radius} fill="none" stroke={theme.surfaceMuted} strokeWidth={RING_STROKE} />
                                <circle
                                    cx={RING_SIZE / 2}
                                    cy={RING_SIZE / 2}
                                    r={radius}
                                    fill="none"
                                    stroke={theme.positive}
                                    strokeWidth={RING_STROKE}
                                    strokeLinecap="round"
                                    strokeDasharray={`${circumference * goalRatio} ${circumference}`}
                                />
                            </svg>
                            <div
                                style={{
                                    position: 'absolute',
                                    inset: 0,
                                    display: 'flex',
                                    flexDirection: 'column',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    gap: 6,
                                }}
                            >
                                <span style={{ fontSize: 64, fontWeight: 600 }}>{Math.round(goalRatio * 100)}%</span>
                                <span style={{ fontSize: 20, color: theme.muted }}>{goal.label}</span>
                            </div>
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8 }}>
                            <span style={{ display: 'flex', alignItems: 'center', gap: 12, fontSize: 30, fontWeight: 600 }}>
                                <PlaneIcon size={28} />
                                {goal.name}
                            </span>
                            <span style={{ fontFamily: fonts.mono, fontSize: 22, color: theme.muted }}>
                                {formatEuros(goal.saved * goalFill, locale, 0)} / {formatEuros(goal.target, locale, 0)}
                            </span>
                        </div>
                    </div>
                </div>
                {overspent && frame >= ALERT_AT && (
                    <div
                        style={{
                            position: 'absolute',
                            right: 590,
                            top: 24,
                            transform: `scale(${alertIn})`,
                            transformOrigin: 'top right',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 16,
                            padding: '16px 24px',
                            borderRadius: 16,
                            background: theme.surface,
                            border: `1.5px solid ${theme.negative}`,
                            boxShadow: theme.shadow,
                        }}
                    >
                        <BellIcon size={26} color={theme.negative} style={{ transform: `rotate(${Math.sin(frame / 2) * 12 * Math.max(0, 1 - (frame - ALERT_AT) / 30)}deg)` }} />
                        <span style={{ fontSize: 22, fontWeight: 600 }}>
                            {overspent.name}: {formatEuros(overspent.spent - overspent.limit, locale, 0)} {copy.budgets.overBudget}
                        </span>
                    </div>
                )}
            </AppWindow>
        </SceneLayout>
    );
}
