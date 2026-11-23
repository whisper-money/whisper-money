import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"

import { cn } from "@/lib/utils"
import { GlowingEffect } from "@/components/ui/glowing-effect"

/**
 * A bordered surface that groups content (docs/design-system.md, "Cards").
 * The parts take their inline padding from the card's `size`:
 *
 * - `default`: a page-level card (24px).
 * - `sm`: a card inside a drawer, dialog or step, or a small card in a row
 *   or grid (16px).
 * - `flush`: no padding and no gap, for a list or grid whose rows carry
 *   their own padding. Put `divide-y` on a child, not on the card (the
 *   card's first children are its hover glow), and give that child
 *   `overflow-hidden rounded-[inherit]` when its rows have a hover fill.
 */
const cardVariants = cva(
    "relative bg-card text-card-foreground flex flex-col rounded-xl border",
    {
        variants: {
            size: {
                default: "gap-6 py-6 [--card-px:--spacing(6)]",
                sm: "gap-4 py-4 [--card-px:--spacing(4)]",
                flush: "gap-0 [--card-px:0px]",
            },
        },
        defaultVariants: {
            size: "default",
        },
    }
)

/** Set by the nearest card, so a card nested in another keeps its own. */
const CARD_PART_PADDING = "px-[var(--card-px,calc(var(--spacing)*6))]"

function Card({
    className,
    size = "default",
    children,
    ...props
}: React.ComponentProps<"div"> & VariantProps<typeof cardVariants>) {
    return (
        <div
            data-slot="card"
            data-size={size}
            className={cn(cardVariants({ size }), className)}
            {...props}
        >
            <GlowingEffect
                spread={40}
                glow={true}
                disabled={false}
                proximity={64}
                inactiveZone={0.01}
            />
            {children}
        </div>
    )
}

function CardHeader({ className, ...props }: React.ComponentProps<"div">) {
    return (
        <div
            data-slot="card-header"
            className={cn("flex flex-col gap-1.5", CARD_PART_PADDING, className)}
            {...props}
        />
    )
}

function CardTitle({ className, ...props }: React.ComponentProps<"div">) {
    return (
        <div
            data-slot="card-title"
            className={cn("leading-none font-semibold", className)}
            {...props}
        />
    )
}

function CardDescription({ className, ...props }: React.ComponentProps<"div">) {
    return (
        <div
            data-slot="card-description"
            className={cn("text-muted-foreground text-sm", className)}
            {...props}
        />
    )
}

function CardContent({ className, ...props }: React.ComponentProps<"div">) {
    return (
        <div
            data-slot="card-content"
            className={cn(CARD_PART_PADDING, className)}
            {...props}
        />
    )
}

function CardFooter({ className, ...props }: React.ComponentProps<"div">) {
    return (
        <div
            data-slot="card-footer"
            className={cn("flex items-center", CARD_PART_PADDING, className)}
            {...props}
        />
    )
}

export { Card, CardHeader, CardFooter, CardTitle, CardDescription, CardContent }
