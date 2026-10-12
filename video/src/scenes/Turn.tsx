import { AbsoluteFill, useCurrentFrame } from 'remotion';
import { enter, rise } from '../animation';
import { Logo } from '../components/Logo';
import { useVideo } from '../context';
import { fonts } from '../fonts';

export function Turn() {
    const frame = useCurrentFrame();
    const { theme, copy } = useVideo();
    const lineIn = enter(frame, 0);
    const brandIn = enter(frame, 30);

    return (
        <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', gap: 40 }}>
            <h1
                style={{
                    margin: 0,
                    opacity: lineIn,
                    transform: rise(lineIn, 30),
                    fontFamily: fonts.serif,
                    fontStyle: 'italic',
                    fontWeight: 600,
                    fontSize: 104,
                    letterSpacing: -2,
                    color: theme.text,
                }}
            >
                {copy.turn.line}
            </h1>
            <div style={{ display: 'flex', alignItems: 'center', gap: 22, opacity: brandIn, transform: rise(brandIn, 16) }}>
                <Logo size={72} drawFrom={22} />
                <span style={{ fontFamily: fonts.sans, fontSize: 54, fontWeight: 600, color: theme.text }}>{copy.turn.brand}</span>
            </div>
        </AbsoluteFill>
    );
}
