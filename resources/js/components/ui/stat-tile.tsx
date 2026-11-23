import * as React from "react"
import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"

import { Skeleton } from "@/components/ui/skeleton"
import { cn } from "@/lib/utils"

/**
 * One figure with what it measures (docs/design-system.md, "Stat tiles"):
 * a label, the value, and optionally a trend and a line of context.
 * Compose only the parts you need:
 *
 *   <StatTile size="lg">
 *     <StatTileLabel>{__('Income')}</StatTileLabel>
 *     <StatTileValue>
 *       <AmountDisplay amountInCents={income} currencyCode={currency} />
 *     </StatTileValue>
 *     <StatTileTrend>
 *       <AmountTrendIndicator … />
 *     </StatTileTrend>
 *   </StatTile>
 *
 * The tile sets the value's type size and weight, so pass `AmountDisplay`
 * without `size` or `weight`: it inherits them, and privacy mode still masks
 * it. `sm` sits in a row of figures inside a card, `md` in a grid of tiles,
 * `lg` is the headline figure of a card. `variant="muted"` puts the tile on
 * its own quiet surface, for a grid of tiles that has no card around each.
 */
const statTileVariants = cva("group/stat-tile flex flex-col", {
  variants: {
    size: {
      sm: "gap-0.5",
      md: "gap-1",
      lg: "gap-1",
    },
    variant: {
      plain: "",
      muted: "rounded-lg bg-muted/50 p-4",
    },
  },
  defaultVariants: {
    size: "md",
    variant: "plain",
  },
})

function StatTile({
  className,
  size = "md",
  variant = "plain",
  ...props
}: React.ComponentProps<"div"> & VariantProps<typeof statTileVariants>) {
  return (
    <div
      data-slot="stat-tile"
      data-size={size}
      data-variant={variant}
      className={cn(statTileVariants({ size, variant }), className)}
      {...props}
    />
  )
}

/**
 * The label's row, when something sits at its end: a badge, an icon, a
 * small action.
 */
function StatTileHeader({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="stat-tile-header"
      className={cn("flex items-center justify-between gap-2", className)}
      {...props}
    />
  )
}

/**
 * What the figure measures: "Income", "Available". In a `<dl>` of figures,
 * pass `asChild` around a `<dt>` (and the value's around a `<dd>`).
 */
function StatTileLabel({
  className,
  asChild = false,
  ...props
}: React.ComponentProps<"p"> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : "p"

  return (
    <Comp
      data-slot="stat-tile-label"
      className={cn("text-xs text-muted-foreground", className)}
      {...props}
    />
  )
}

/**
 * The figure: an `AmountDisplay` for money, or a plain number or
 * percentage. A tone (`text-success` for money in the user's favour) goes on
 * `className`.
 */
function StatTileValue({
  className,
  asChild = false,
  ...props
}: React.ComponentProps<"div"> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : "div"

  return (
    <Comp
      data-slot="stat-tile-value"
      className={cn(
        "font-semibold tabular-nums group-data-[size=sm]/stat-tile:text-base group-data-[size=md]/stat-tile:text-lg group-data-[size=lg]/stat-tile:text-2xl",
        className
      )}
      {...props}
    />
  )
}

/**
 * The change against a previous period: an `AmountTrendIndicator`, a
 * `PercentageTrendIndicator` or the cashflow comparisons. Favourable is
 * `success`, unfavourable `muted-foreground`; never red.
 */
function StatTileTrend({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="stat-tile-trend"
      className={cn(
        "flex flex-col gap-0.5 text-xs group-data-[size=lg]/stat-tile:text-sm",
        className
      )}
      {...props}
    />
  )
}

/** A line of context under the figure: what it holds, what it is of. */
function StatTileDescription({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="stat-tile-description"
      className={cn(
        "text-xs text-muted-foreground group-data-[size=lg]/stat-tile:text-sm",
        className
      )}
      {...props}
    />
  )
}

const valueSkeletonSizes = {
  sm: "h-6 w-24",
  md: "h-7 w-24",
  lg: "h-8 w-32",
} as const

/** The tile while its figure loads, the same height as the tile it becomes. */
function StatTileSkeleton({
  size = "md",
  variant = "plain",
  withTrend = false,
  className,
  ...props
}: Omit<React.ComponentProps<"div">, "children"> &
  VariantProps<typeof statTileVariants> & { withTrend?: boolean }) {
  const resolvedSize = size ?? "md"

  return (
    <StatTile
      size={resolvedSize}
      variant={variant}
      aria-hidden="true"
      className={className}
      {...props}
    >
      <Skeleton className="h-3 w-16" />
      <Skeleton className={valueSkeletonSizes[resolvedSize]} />
      {withTrend && <Skeleton className="h-4 w-28" />}
    </StatTile>
  )
}

export {
  StatTile,
  StatTileHeader,
  StatTileLabel,
  StatTileValue,
  StatTileTrend,
  StatTileDescription,
  StatTileSkeleton,
  statTileVariants,
}
