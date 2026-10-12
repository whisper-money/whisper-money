import { AbsoluteFill, interpolate, useCurrentFrame } from 'remotion';
import { enter, rise } from '../animation';
import { useVideo } from '../context';
import { fonts } from '../fonts';

export function Hook() {
    const frame = useCurrentFrame();
    const { theme, copy } = useVideo();
    const zoom = interpolate(frame, [0, 100], [1, 1.06]);
    const answerIn = enter(frame, 44);

    return (
        <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center' }}>
            <div
                style={{
                    transform: `scale(${zoom})`,
                    display: 'flex',
                    flexDirection: 'column',
                    alignItems: 'center',
                    gap: 36,
                }}
            >
                <h1
                    style={{
                        margin: 0,
                        maxWidth: 1400,
                        textAlign: 'center',
                        fontFamily: fonts.serif,
                        fontStyle: 'italic',
                        fontWeight: 600,
                        fontSize: 112,
                        lineHeight: 1.1,
                        letterSpacing: -2,
                        color: theme.text,
                    }}
                >
                    {copy.hook.question.map((word, index) => {
                        const wordIn = enter(frame, index * 4, 18);

                        return (
                            <span
                                key={`${word}-${index}`}
                                style={{
                                    display: 'inline-block',
                                    marginRight: '0.25em',
                                    opacity: wordIn,
                                    filter: `blur(${(1 - wordIn) * 10}px)`,
                                    transform: rise(wordIn, 30),
                                }}
                            >
                                {word}
                            </span>
                        );
                    })}
                </h1>
                <p
                    style={{
                        margin: 0,
                        opacity: answerIn,
                        transform: rise(answerIn, 16),
                        fontFamily: fonts.sans,
                        fontWeight: 500,
                        fontSize: 40,
                        color: theme.muted,
                    }}
                >
                    {copy.hook.answer}
                </p>
            </div>
        </AbsoluteFill>
    );
}
