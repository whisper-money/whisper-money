import { useVideo } from '../context';

export function Cursor({ x, y, pressed }: { x: number; y: number; pressed: number }) {
    const { theme } = useVideo();
    const scale = 1 - pressed * 0.15;

    return (
        <svg
            width={44}
            height={44}
            viewBox="0 0 24 24"
            style={{
                position: 'absolute',
                left: x,
                top: y,
                transform: `scale(${scale})`,
                transformOrigin: 'top left',
                filter: 'drop-shadow(0 6px 12px rgba(0,0,0,0.35))',
            }}
        >
            <path
                d="M4 2.5 L19.5 11.2 L12.6 12.9 L9.4 19.6 Z"
                fill={theme.name === 'dark' ? '#ffffff' : '#111111'}
                stroke={theme.name === 'dark' ? '#111111' : '#ffffff'}
                strokeWidth={1.4}
                strokeLinejoin="round"
            />
        </svg>
    );
}
