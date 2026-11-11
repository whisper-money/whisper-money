import { Progress, useWidgetData } from '@whisper-money/widget-sdk';

function Ring({ percent }: { percent: number }) {
    const circumference = 2 * Math.PI * 28;

    return (
        <svg
            viewBox="0 0 64 64"
            className="size-16"
            role="img"
            aria-label={`${percent}%`}
        >
            <circle
                cx="32"
                cy="32"
                r="28"
                fill="none"
                stroke="var(--color-muted)"
                strokeWidth={6}
            />
            <circle
                cx="32"
                cy="32"
                r="28"
                fill="none"
                stroke="var(--color-success)"
                strokeWidth={6}
                strokeDasharray={`${(percent / 100) * circumference} ${circumference}`}
                transform="rotate(-90 32 32)"
            />
        </svg>
    );
}

export default function SavingsRing() {
    const { savings } = useWidgetData();
    const percent = Math.round((savings.saved / savings.goal) * 100);

    return (
        <div className="flex items-center gap-4 p-4">
            <Ring percent={percent} />
            <div className="flex flex-1 flex-col gap-2">
                <p className="text-sm font-medium">Ahorro del mes</p>
                <Progress value={percent} />
                <small className="text-muted-foreground">
                    {percent}% del objetivo
                </small>
            </div>
        </div>
    );
}
