import { useEffect, useState } from 'react';

interface UseCountUpOptions {
    duration?: number;
    delay?: number;
}

export function useCountUp(
    target: number,
    options: UseCountUpOptions = {},
): number {
    const { duration = 1500, delay = 0 } = options;
    const [count, setCount] = useState(0);

    useEffect(() => {
        if (target === 0) {
            setCount(0);
            return;
        }

        const delayTimeout = setTimeout(() => {
            const startTime = performance.now();
            const startValue = 0;

            const animate = (currentTime: number) => {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);

                const easeOutQuad = 1 - (1 - progress) * (1 - progress);
                const currentValue = Math.round(
                    startValue + (target - startValue) * easeOutQuad,
                );

                setCount(currentValue);

                if (progress < 1) {
                    requestAnimationFrame(animate);
                }
            };

            requestAnimationFrame(animate);
        }, delay);

        return () => clearTimeout(delayTimeout);
    }, [target, duration, delay]);

    return count;
}
