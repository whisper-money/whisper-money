import { EyeOffIcon } from 'lucide-react';
import { AbsoluteFill, interpolate, random, useCurrentFrame } from 'remotion';
import { enter, rise } from '../animation';
import { Caption } from '../components/Caption';
import { useVideo } from '../context';
import { fonts } from '../fonts';

/** Small charges that drift away from the balance and fade out of sight. */
const LEAK_POSITIONS = [
    { x: -560, y: -60 },
    { x: 520, y: -110 },
    { x: -480, y: 150 },
    { x: 560, y: 120 },
    { x: -150, y: 250 },
    { x: 220, y: 270 },
];

function scrambledAmount(frame: number): string {
    const digits = Array.from({ length: 6 }, (_, index) =>
        Math.floor(random(`digit-${index}-${Math.floor(frame / 2)}`) * 10),
    );

    return `€${digits[0]},${digits[1]}${digits[2]}${digits[3]}.${digits[4]}${digits[5]}`;
}

export function NoTracking() {
    const frame = useCurrentFrame();
    const { theme, copy } = useVideo();
    const cardIn = enter(frame, 6);

    return (
        <AbsoluteFill style={{ alignItems: 'center' }}>
            <div style={{ marginTop: 110 }}>
                <Caption
                    label={copy.noTracking.label}
                    title={copy.noTracking.title}
                    subtitle={copy.noTracking.subtitle}
                />
            </div>
            <div style={{ position: 'absolute', left: 960, top: 690 }}>
                {copy.noTracking.leaks.map((leak, index) => {
                    const start = 18 + index * 9;
                    const life = interpolate(frame, [start, start + 70], [0, 1], {
                        extrapolateLeft: 'clamp',
                        extrapolateRight: 'clamp',
                    });
                    const target = LEAK_POSITIONS[index];
                    const opacity = interpolate(life, [0, 0.15, 0.7, 1], [0, 1, 0.8, 0]);

                    return (
                        <div
                            key={leak}
                            style={{
                                position: 'absolute',
                                transform: `translate(-50%, -50%) translate(${target.x * life}px, ${target.y * life}px)`,
                                opacity,
                                filter: `blur(${Math.max(0, life - 0.5) * 12}px)`,
                                whiteSpace: 'nowrap',
                                fontFamily: fonts.sans,
                                fontSize: 24,
                                fontWeight: 500,
                                color: theme.negative,
                                background: theme.negativeSoft,
                                borderRadius: 999,
                                padding: '10px 22px',
                            }}
                        >
                            {leak}
                        </div>
                    );
                })}
                <div
                    style={{
                        position: 'absolute',
                        transform: `translate(-50%, -50%) ${rise(cardIn, 40)}`,
                        opacity: cardIn,
                        width: 620,
                        padding: '40px 48px',
                        borderRadius: 28,
                        background: theme.surface,
                        border: `1.5px solid ${theme.border}`,
                        boxShadow: theme.shadow,
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 12,
                        fontFamily: fonts.sans,
                    }}
                >
                    <div
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 12,
                            color: theme.muted,
                            fontSize: 26,
                            fontWeight: 500,
                        }}
                    >
                        <EyeOffIcon size={26} />
                        {copy.noTracking.balanceLabel}
                    </div>
                    <div
                        style={{
                            fontFamily: fonts.mono,
                            fontSize: 92,
                            fontWeight: 600,
                            color: theme.text,
                            filter: 'blur(5px)',
                            opacity: 0.7,
                        }}
                    >
                        {scrambledAmount(frame)}
                    </div>
                </div>
            </div>
        </AbsoluteFill>
    );
}
