import * as React from "react"
import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"

import { cn } from "@/lib/utils"

/**
 * What a list, table, chart or page shows when it has nothing to show
 * (docs/design-system.md, "Empty states"). Mirrors shadcn's `Empty`:
 *
 *   <EmptyState size="inline">
 *     <EmptyStateDescription>{__('No labels found.')}</EmptyStateDescription>
 *   </EmptyState>
 *
 * `page` stands alone on a page, in a dashed frame; `inline` sits inside
 * something that already frames it (a card, a table cell, a drawer, a menu).
 */
const emptyStateVariants = cva(
  "group/empty-state flex min-w-0 flex-col items-center justify-center text-center text-balance",
  {
    variants: {
      size: {
        page: "gap-4 rounded-xl border border-dashed p-8",
        inline: "gap-3 px-4 py-6",
      },
    },
    defaultVariants: {
      size: "page",
    },
  }
)

function EmptyState({
  className,
  size = "page",
  ...props
}: React.ComponentProps<"div"> & VariantProps<typeof emptyStateVariants>) {
  return (
    <div
      data-slot="empty-state"
      data-size={size}
      className={cn(emptyStateVariants({ size }), className)}
      {...props}
    />
  )
}

/** Groups the media, title and description above the actions. */
function EmptyStateHeader({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="empty-state-header"
      className={cn("flex max-w-md flex-col items-center gap-1.5", className)}
      {...props}
    />
  )
}

const emptyStateMediaVariants = cva(
  "flex shrink-0 items-center justify-center [&_svg]:pointer-events-none [&_svg]:shrink-0",
  {
    variants: {
      variant: {
        default:
          "text-muted-foreground [&_svg:not([class*='size-'])]:size-8 group-data-[size=inline]/empty-state:[&_svg:not([class*='size-'])]:size-5",
        icon: "size-10 rounded-lg bg-muted text-foreground [&_svg:not([class*='size-'])]:size-5",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

/**
 * A lucide icon (or any small graphic) above the title. Decorative, so hidden
 * from assistive tech; pass `aria-hidden={false}` if it carries meaning.
 */
function EmptyStateMedia({
  className,
  variant = "default",
  ...props
}: React.ComponentProps<"div"> & VariantProps<typeof emptyStateMediaVariants>) {
  return (
    <div
      data-slot="empty-state-media"
      data-variant={variant}
      aria-hidden="true"
      className={cn(emptyStateMediaVariants({ variant }), className)}
      {...props}
    />
  )
}

/** Pass `asChild` to render it as the page's heading (`<h1>`, `<h2>`…). */
function EmptyStateTitle({
  className,
  asChild = false,
  ...props
}: React.ComponentProps<"div"> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : "div"

  return (
    <Comp
      data-slot="empty-state-title"
      className={cn(
        "text-base font-medium text-foreground group-data-[size=inline]/empty-state:text-sm",
        className
      )}
      {...props}
    />
  )
}

function EmptyStateDescription({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="empty-state-description"
      className={cn(
        "max-w-prose text-sm text-pretty text-muted-foreground [&_a]:font-medium [&_a]:text-foreground [&_a]:underline [&_a]:underline-offset-4",
        className
      )}
      {...props}
    />
  )
}

/** The actions: a button, a link, a menu. */
function EmptyStateContent({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="empty-state-content"
      className={cn(
        "flex flex-wrap items-center justify-center gap-2",
        className
      )}
      {...props}
    />
  )
}

export {
  EmptyState,
  EmptyStateHeader,
  EmptyStateMedia,
  EmptyStateTitle,
  EmptyStateDescription,
  EmptyStateContent,
  emptyStateVariants,
}
