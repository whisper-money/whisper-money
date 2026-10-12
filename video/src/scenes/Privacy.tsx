import { CheckIcon, LockIcon } from 'lucide-react';
import { AbsoluteFill, interpolate, useCurrentFrame } from 'remotion';
import { enter, pop, rise } from '../animation';
import { Caption } from '../components/Caption';
import { useVideo } from '../context';
import { fonts } from '../fonts';

export function Privacy() {
    const frame = useCurrentFrame();
    const { theme, copy } = useVideo();
    const lockIn = pop(frame, 0);
    const shackle = interpolate(frame, [10, 22], [-14, 0], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

    return (
        <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', gap: 56 }}>
            <div style={{ position: 'relative', width: 200, height: 200, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                {[0, 1, 2].map((ring) => {
                    const pulse = ((frame + ring * 20) % 60) / 60;

                    return (
                        <div
                            key={ring}
                            style={{
                                position: 'absolute',
                                inset: 0,
                                borderRadius: 999,
                                border: `2px solid ${theme.text}`,
                                opacity: (1 - pulse) * 0.25 * lockIn,
                                transform: `scale(${1 + pulse * 0.9})`,
                            }}
                        />
                    );
                })}
                <div
                    style={{
                        width: 200,
                        height: 200,
                        borderRadius: 999,
                        background: theme.surface,
                        border: `1.5px solid ${theme.border}`,
                        boxShadow: theme.shadow,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        transform: `scale(${lockIn})`,
                    }}
                >
                    <LockIcon size={92} strokeWidth={1.6} color={theme.text} style={{ transform: `translateY(${shackle * 0.2}px)` }} />
                </div>
            </div>
            <Caption title={copy.privacy.title} subtitle={copy.privacy.subtitle} delay={8} titleSize={96} />
            <div style={{ display: 'flex', gap: 20 }}>
                {copy.privacy.pills.map((pill, index) => {
                    const pillIn = pop(frame, 32 + index * 7);
                    const fade = enter(frame, 32 + index * 7);

                    return (
                        <div
                            key={pill}
                            style={{
                                opacity: fade,
                                transform: `${rise(fade, 16)} scale(${0.6 + pillIn * 0.4})`,
                                display: 'flex',
                                alignItems: 'center',
                                gap: 12,
                                padding: '16px 28px',
                                borderRadius: 999,
                                border: `1.5px solid ${theme.border}`,
                                background: theme.surface,
                                fontFamily: fonts.sans,
                                fontSize: 28,
                                fontWeight: 500,
                                color: theme.text,
                            }}
                        >
                            <CheckIcon size={26} strokeWidth={2.6} color={theme.positive} />
                            {pill}
                        </div>
                    );
                })}
            </div>
        </AbsoluteFill>
    );
}
