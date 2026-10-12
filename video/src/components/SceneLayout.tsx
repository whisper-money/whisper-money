import { type ReactNode } from 'react';
import { AbsoluteFill, useCurrentFrame } from 'remotion';
import { enter } from '../animation';
import { Caption } from './Caption';

/** Caption on top and an app window rising from the bottom of the frame. */
export function SceneLayout({ title, subtitle, children }: { title: string; subtitle: string; children: ReactNode }) {
    const frame = useCurrentFrame();
    const windowIn = enter(frame, 8, 34);

    return (
        <AbsoluteFill style={{ alignItems: 'center' }}>
            <div style={{ marginTop: 64 }}>
                <Caption title={title} subtitle={subtitle} titleSize={68} />
            </div>
            <div
                style={{
                    position: 'absolute',
                    top: 300,
                    opacity: windowIn,
                    transform: `translateY(${(1 - windowIn) * 160}px) scale(${0.94 + windowIn * 0.06})`,
                }}
            >
                {children}
            </div>
        </AbsoluteFill>
    );
}
