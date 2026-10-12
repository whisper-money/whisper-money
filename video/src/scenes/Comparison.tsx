import { CheckIcon, MinusIcon, XIcon } from 'lucide-react';
import { AbsoluteFill, useCurrentFrame } from 'remotion';
import { enter, pop } from '../animation';
import { Caption } from '../components/Caption';
import { Logo } from '../components/Logo';
import { useVideo } from '../context';
import { fonts } from '../fonts';

const LABEL_WIDTH = 560;
const COLUMN_WIDTH = 330;
const ROW_HEIGHT = 96;

function ComparisonValue({
    value,
    frame,
    delay,
    isHighlighted,
}: {
    value: string;
    frame: number;
    delay: number;
    isHighlighted: boolean;
}) {
    const { theme } = useVideo();
    const valueIn = pop(frame, delay);
    const icons = {
        yes: { Icon: CheckIcon, color: theme.positive, background: theme.positiveSoft },
        no: { Icon: XIcon, color: theme.faint, background: 'transparent' },
        partial: { Icon: MinusIcon, color: theme.warning, background: 'transparent' },
    } as const;
    const icon = icons[value as keyof typeof icons];

    return (
        <div style={{ transform: `scale(${valueIn})`, opacity: Math.min(1, valueIn) }}>
            {icon ? (
                <div
                    style={{
                        width: 52,
                        height: 52,
                        borderRadius: 999,
                        background: icon.background,
                        color: icon.color,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                    }}
                >
                    <icon.Icon size={32} strokeWidth={2.6} />
                </div>
            ) : (
                <span style={{ fontSize: 28, fontWeight: 600, color: isHighlighted ? theme.positive : theme.muted }}>{value}</span>
            )}
        </div>
    );
}

export function Comparison() {
    const frame = useCurrentFrame();
    const { theme, copy } = useVideo();
    const tableIn = enter(frame, 6, 30);
    const highlightIn = enter(frame, 20, 30);
    const rows = copy.comparison.rows;
    const tableHeight = ROW_HEIGHT * (rows.length + 1);

    return (
        <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', gap: 64, fontFamily: fonts.sans, color: theme.text }}>
            <Caption title={copy.comparison.title} titleSize={80} />
            <div style={{ position: 'relative', opacity: tableIn, transform: `translateY(${(1 - tableIn) * 40}px)` }}>
                <div
                    style={{
                        position: 'absolute',
                        left: LABEL_WIDTH + COLUMN_WIDTH * 2,
                        top: -16,
                        width: COLUMN_WIDTH,
                        height: tableHeight + 32,
                        borderRadius: 28,
                        background: theme.surface,
                        border: `2px solid ${theme.text}`,
                        boxShadow: theme.shadow,
                        opacity: highlightIn,
                        transform: `scale(${0.96 + highlightIn * 0.04})`,
                    }}
                />
                <div style={{ position: 'relative', display: 'flex', height: ROW_HEIGHT, alignItems: 'center' }}>
                    <div style={{ width: LABEL_WIDTH }} />
                    {copy.comparison.columns.map((column, index) => (
                        <div
                            key={column}
                            style={{
                                width: COLUMN_WIDTH,
                                display: 'flex',
                                justifyContent: 'center',
                                alignItems: 'center',
                                gap: 12,
                                fontSize: 28,
                                fontWeight: 600,
                                color: index === 2 ? theme.text : theme.muted,
                            }}
                        >
                            {index === 2 && <Logo size={34} />}
                            {column}
                        </div>
                    ))}
                </div>
                {rows.map((row, rowIndex) => {
                    const rowDelay = 24 + rowIndex * 10;
                    const rowIn = enter(frame, rowDelay);

                    return (
                        <div
                            key={row.label}
                            style={{
                                position: 'relative',
                                display: 'flex',
                                height: ROW_HEIGHT,
                                alignItems: 'center',
                                borderTop: `1.5px solid ${theme.border}`,
                                opacity: rowIn,
                            }}
                        >
                            <div style={{ width: LABEL_WIDTH, fontSize: 30, fontWeight: 500, paddingLeft: 8 }}>{row.label}</div>
                            {row.values.map((value, columnIndex) => (
                                <div key={columnIndex} style={{ width: COLUMN_WIDTH, display: 'flex', justifyContent: 'center' }}>
                                    <ComparisonValue value={value} frame={frame} delay={rowDelay + 4 + columnIndex * 4} isHighlighted={columnIndex === 2} />
                                </div>
                            ))}
                        </div>
                    );
                })}
            </div>
        </AbsoluteFill>
    );
}
