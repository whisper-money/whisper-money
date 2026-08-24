import { cn } from '@/lib/utils';
import { Sparkles } from 'lucide-react';

/**
 * The Gemini-style multi-color sparkle that marks anything powered by AI
 * (AI-guessed categories, AI-generated rules, AI filters, ...). This is the
 * single shared icon to reuse wherever a feature involves AI.
 *
 * The gradient is applied to the SVG via a referenced linearGradient so the
 * stroke and fill render as a true multi-color gradient (not a flat color).
 */
export function AiSparkleIcon({ className }: { className?: string }) {
    return (
        <>
            <svg width="0" height="0" className="absolute" aria-hidden="true">
                <defs>
                    <linearGradient
                        id="ai-sparkle-gradient"
                        x1="0%"
                        y1="0%"
                        x2="100%"
                        y2="100%"
                    >
                        <stop offset="0%" stopColor="#4796E3" />
                        <stop offset="50%" stopColor="#9177C7" />
                        <stop offset="100%" stopColor="#D56F82" />
                    </linearGradient>
                </defs>
            </svg>
            <Sparkles
                className={cn('h-4 w-4', className)}
                stroke="url(#ai-sparkle-gradient)"
                fill="url(#ai-sparkle-gradient)"
            />
        </>
    );
}
