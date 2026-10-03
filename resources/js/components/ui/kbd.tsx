import * as React from "react"

import { cn } from "@/lib/utils"

function Kbd({ className, ...props }: React.ComponentProps<"kbd">) {
    return (
        <kbd
            data-slot="kbd"
            className={cn(
                "bg-muted text-muted-foreground pointer-events-none h-5 w-fit min-w-5 items-center justify-center gap-1 rounded-sm px-1 font-sans text-xs font-medium select-none",
                "[&_svg:not([class*='size-'])]:size-3",
                "[[data-slot=tooltip-content]_&]:bg-background/20 [[data-slot=tooltip-content]_&]:text-background dark:[[data-slot=tooltip-content]_&]:bg-background/10",
                // Grey is too loud on a primary button: a tint of its text color
                // reads on it in both themes.
                "[[data-slot=button][data-variant=default]_&]:bg-primary-foreground/15 [[data-slot=button][data-variant=default]_&]:text-primary-foreground/70",
                // Ghost and outline buttons hover to the chip's own grey, so on
                // hover it turns to the background color with an outline.
                "[[data-slot=button]:is([data-variant=ghost],[data-variant=outline]):hover_&]:bg-background [[data-slot=button]:is([data-variant=ghost],[data-variant=outline]):hover_&]:ring-1 [[data-slot=button]:is([data-variant=ghost],[data-variant=outline]):hover_&]:ring-border",
                "hidden sm:inline-flex",
                className
            )}
            {...props}
        />
    )
}

function KbdGroup({ className, ...props }: React.ComponentProps<"span">) {
    return (
        <span
            data-slot="kbd-group"
            className={cn("inline-flex items-center gap-0.5", className)}
            {...props}
        />
    )
}

export { Kbd, KbdGroup }

