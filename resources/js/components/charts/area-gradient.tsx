/**
 * The vertical fade under an area series, strong at the line and nearly gone
 * at the axis. Render it inside the chart's `<defs>` and point the `Area`'s
 * `fill` at `url(#<id>)`.
 */
export function AreaGradient({ id, color }: { id: string; color: string }) {
    return (
        <linearGradient id={id} x1="0" y1="0" x2="0" y2="1">
            <stop offset="5%" stopColor={color} stopOpacity={0.3} />
            <stop offset="95%" stopColor={color} stopOpacity={0.05} />
        </linearGradient>
    );
}
