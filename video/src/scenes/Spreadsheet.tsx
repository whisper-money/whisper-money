import { ClockIcon, FileSpreadsheetIcon } from 'lucide-react';
import { AbsoluteFill, Easing, interpolate, useCurrentFrame } from 'remotion';
import { enter, progress } from '../animation';
import { Caption } from '../components/Caption';
import { useVideo } from '../context';
import { fonts } from '../fonts';

const COLUMN_WIDTHS = [150, 370, 220, 230];
const ROW_HEIGHT = 58;
const ROW_START = 20;
const ROW_GAP = 14;
const ERROR_AT = 118;

function typed(text: string, frame: number, start: number): string {
    const characters = Math.floor(Math.max(0, frame - start) * 1.6);

    return text.slice(0, characters);
}

function formatDuration(minutes: number): string {
    const hours = Math.floor(minutes / 60);
    const rest = Math.floor(minutes % 60);

    return `${hours}h ${String(rest).padStart(2, '0')}m`;
}

export function Spreadsheet() {
    const frame = useCurrentFrame();
    const { theme, copy } = useVideo();
    const windowIn = enter(frame, 0, 30);
    const minutesSpent = interpolate(frame, [10, 150], [0, 225], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing: Easing.in(Easing.quad),
    });
    const errorIn = progress(frame, ERROR_AT, ERROR_AT + 6);
    const shake = frame >= ERROR_AT && frame < ERROR_AT + 14 ? Math.sin(frame * 2.4) * 10 * (1 - (frame - ERROR_AT) / 14) : 0;
    const cellBorder = `1px solid ${theme.sheetGrid}`;

    return (
        <AbsoluteFill style={{ flexDirection: 'row', alignItems: 'center', padding: '0 120px', gap: 90 }}>
            <div style={{ width: 560 }}>
                <Caption
                    label={copy.spreadsheet.label}
                    title={copy.spreadsheet.title}
                    subtitle={copy.spreadsheet.subtitle}
                    align="left"
                    titleSize={72}
                />
            </div>
            <div
                style={{
                    position: 'relative',
                    opacity: windowIn,
                    transform: `perspective(1800px) rotateY(${(1 - windowIn) * -18 - 6}deg) translateX(${shake}px)`,
                    width: 1030,
                    borderRadius: 22,
                    border: `1.5px solid ${theme.border}`,
                    background: theme.surface,
                    boxShadow: theme.shadow,
                    fontFamily: fonts.sans,
                    color: theme.text,
                }}
            >
                <div
                    style={{
                        height: 64,
                        display: 'flex',
                        alignItems: 'center',
                        gap: 14,
                        padding: '0 24px',
                        borderBottom: `1.5px solid ${theme.border}`,
                        fontSize: 22,
                        fontWeight: 500,
                    }}
                >
                    <div style={{ display: 'flex', gap: 8, marginRight: 10 }}>
                        {['#ff5f57', '#febc2e', '#28c840'].map((color) => (
                            <div key={color} style={{ width: 14, height: 14, borderRadius: 999, background: color }} />
                        ))}
                    </div>
                    <FileSpreadsheetIcon size={24} color={theme.muted} />
                    {copy.spreadsheet.fileName}
                </div>
                <div style={{ display: 'flex', fontFamily: fonts.mono, fontSize: 21 }}>
                    <div style={{ width: 54, background: theme.sheetHeader, borderRight: cellBorder }}>
                        {Array.from({ length: copy.spreadsheet.rows.length + 3 }, (_, index) => (
                            <div
                                key={index}
                                style={{
                                    height: ROW_HEIGHT,
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    color: theme.faint,
                                    borderBottom: cellBorder,
                                }}
                            >
                                {index === 0 ? '' : index}
                            </div>
                        ))}
                    </div>
                    <div style={{ flex: 1 }}>
                        <div style={{ display: 'flex', height: ROW_HEIGHT, background: theme.sheetHeader, borderBottom: cellBorder }}>
                            {copy.spreadsheet.columns.map((column, index) => (
                                <div
                                    key={column}
                                    style={{
                                        width: COLUMN_WIDTHS[index],
                                        display: 'flex',
                                        alignItems: 'center',
                                        padding: '0 16px',
                                        borderRight: cellBorder,
                                        fontWeight: 600,
                                        color: theme.muted,
                                    }}
                                >
                                    {column}
                                </div>
                            ))}
                        </div>
                        {copy.spreadsheet.rows.map((row, rowIndex) => {
                            const rowStart = ROW_START + rowIndex * ROW_GAP;

                            return (
                                <div key={row.join()} style={{ display: 'flex', height: ROW_HEIGHT, borderBottom: cellBorder }}>
                                    {row.map((cell, cellIndex) => {
                                        const cellStart = rowStart + cellIndex * 3;
                                        const text = typed(cell, frame, cellStart);
                                        const isTyping = text.length > 0 && text.length < cell.length;
                                        const isUnknown = cell === '???';

                                        return (
                                            <div
                                                key={cellIndex}
                                                style={{
                                                    width: COLUMN_WIDTHS[cellIndex],
                                                    display: 'flex',
                                                    alignItems: 'center',
                                                    justifyContent: cellIndex === 3 ? 'flex-end' : 'flex-start',
                                                    padding: '0 16px',
                                                    borderRight: cellBorder,
                                                    color: isUnknown ? theme.warning : theme.text,
                                                    background: isUnknown && text.length > 0 ? `${theme.warning}22` : 'transparent',
                                                    outline: isTyping ? `2px solid ${theme.positive}` : 'none',
                                                    outlineOffset: -2,
                                                }}
                                            >
                                                {text}
                                                {isTyping && <span style={{ opacity: frame % 10 < 5 ? 1 : 0 }}>|</span>}
                                            </div>
                                        );
                                    })}
                                </div>
                            );
                        })}
                        <div style={{ display: 'flex', height: ROW_HEIGHT, borderBottom: cellBorder }}>
                            <div
                                style={{
                                    width: COLUMN_WIDTHS[0] + COLUMN_WIDTHS[1] + COLUMN_WIDTHS[2],
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'flex-end',
                                    padding: '0 16px',
                                    borderRight: cellBorder,
                                    fontWeight: 600,
                                    color: theme.muted,
                                }}
                            >
                                {copy.spreadsheet.total}
                            </div>
                            <div
                                style={{
                                    width: COLUMN_WIDTHS[3],
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'flex-end',
                                    padding: '0 16px',
                                    borderRight: cellBorder,
                                    fontWeight: 600,
                                    color: frame >= ERROR_AT ? theme.negative : theme.text,
                                    background: frame >= ERROR_AT ? theme.negativeSoft : 'transparent',
                                    transform: `scale(${1 + errorIn * 0.08 - Math.max(0, errorIn - 0.5) * 0.16})`,
                                }}
                            >
                                {frame >= ERROR_AT ? copy.spreadsheet.formulaError : frame > 100 ? copy.spreadsheet.formula : ''}
                            </div>
                        </div>
                        <div style={{ height: ROW_HEIGHT }} />
                    </div>
                </div>
                <div
                    style={{
                        position: 'absolute',
                        right: -28,
                        top: -44,
                        display: 'flex',
                        alignItems: 'center',
                        gap: 14,
                        padding: '14px 22px',
                        borderRadius: 16,
                        background: theme.surface,
                        border: `1.5px solid ${theme.border}`,
                        boxShadow: theme.shadow,
                    }}
                >
                    <ClockIcon size={30} color={theme.warning} />
                    <div style={{ display: 'flex', flexDirection: 'column' }}>
                        <span style={{ fontSize: 16, color: theme.muted, fontWeight: 500 }}>{copy.spreadsheet.timeSpent}</span>
                        <span style={{ fontFamily: fonts.mono, fontSize: 30, fontWeight: 600 }}>{formatDuration(minutesSpent)}</span>
                    </div>
                </div>
            </div>
        </AbsoluteFill>
    );
}
