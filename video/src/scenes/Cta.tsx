import { AbsoluteFill, Easing, interpolate, useCurrentFrame } from 'remotion';
import { enter, pop, rise } from '../animation';
import { Cursor } from '../components/Cursor';
import { LogoTile } from '../components/Logo';
import { useVideo } from '../context';
import { fonts } from '../fonts';

const CLICK_AT = 70;

export function Cta() {
    const frame = useCurrentFrame();
    const { theme, copy } = useVideo();
    const logoIn = pop(frame, 0);
    const titleIn = enter(frame, 8);
    const buttonIn = enter(frame, 20);
    const footIn = enter(frame, 30);
    const cursorMove = interpolate(frame, [36, CLICK_AT - 4], [0, 1], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing: Easing.bezier(0.45, 0, 0.2, 1),
    });
    const pressed = interpolate(frame, [CLICK_AT - 4, CLICK_AT, CLICK_AT + 6], [0, 1, 0], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
    });
    const ripple = interpolate(frame, [CLICK_AT, CLICK_AT + 24], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

    return (
        <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', gap: 44, fontFamily: fonts.sans }}>
            <div style={{ transform: `scale(${logoIn})` }}>
                <LogoTile size={132} />
            </div>
            <h1
                style={{
                    margin: 0,
                    opacity: titleIn,
                    transform: rise(titleIn, 30),
                    fontFamily: fonts.serif,
                    fontStyle: 'italic',
                    fontWeight: 600,
                    fontSize: 104,
                    letterSpacing: -2,
                    color: theme.text,
                }}
            >
                {copy.cta.title}
            </h1>
            <div style={{ position: 'relative', opacity: buttonIn, transform: rise(buttonIn, 24) }}>
                {frame >= CLICK_AT && (
                    <div
                        style={{
                            position: 'absolute',
                            inset: 0,
                            borderRadius: 18,
                            border: `3px solid ${theme.text}`,
                            opacity: 1 - ripple,
                            transform: `scale(${1 + ripple * 0.25})`,
                        }}
                    />
                )}
                <div
                    style={{
                        padding: '30px 72px',
                        borderRadius: 18,
                        background: `linear-gradient(to top, ${theme.buttonFrom}, ${theme.buttonTo})`,
                        color: theme.buttonText,
                        fontSize: 38,
                        fontWeight: 600,
                        boxShadow: theme.shadow,
                        transform: `scale(${1 - pressed * 0.05})`,
                    }}
                >
                    {copy.cta.button}
                </div>
                <div style={{ position: 'absolute', left: 0, top: 0 }}>
                    <Cursor x={interpolate(cursorMove, [0, 1], [680, 400])} y={interpolate(cursorMove, [0, 1], [260, 70])} pressed={pressed} />
                </div>
            </div>
            <div style={{ opacity: footIn, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 10 }}>
                <span style={{ fontSize: 28, color: theme.muted, fontWeight: 500 }}>{copy.cta.footnote}</span>
                <span style={{ fontFamily: fonts.mono, fontSize: 30, color: theme.text, fontWeight: 500 }}>{copy.cta.url}</span>
            </div>
        </AbsoluteFill>
    );
}
