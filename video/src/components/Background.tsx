import { AbsoluteFill, useCurrentFrame } from 'remotion';
import { useVideo } from '../context';

/** Page background with the same soft radial glow the landing hero uses. */
export function Background() {
    const frame = useCurrentFrame();
    const { theme } = useVideo();
    const drift = Math.sin(frame / 90) * 40;

    return (
        <AbsoluteFill style={{ background: theme.background }}>
            <AbsoluteFill
                style={{
                    background: `radial-gradient(ellipse 60% 50% at ${50 + drift / 20}% ${62 + drift / 30}%, ${theme.glow}, transparent 70%)`,
                }}
            />
        </AbsoluteFill>
    );
}
