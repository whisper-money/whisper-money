import * as React from "react"

import { cn } from "@/lib/utils"

/**
 * An on/off switch, 44px wide. Name it with `aria-label`, or with a `<Label
 * htmlFor>` pointing at its `id`.
 */
function Switch({
  checked,
  onCheckedChange,
  className,
  ...props
}: Omit<React.ComponentProps<"button">, "onChange"> & {
  checked: boolean
  onCheckedChange: (checked: boolean) => void
}) {
  return (
    <button
      {...props}
      type="button"
      role="switch"
      aria-checked={checked}
      data-slot="switch"
      data-state={checked ? "checked" : "unchecked"}
      onClick={() => onCheckedChange(!checked)}
      className={cn(
        "relative h-[26px] w-11 shrink-0 rounded-full transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none",
        checked ? "bg-primary" : "bg-neutral-300 dark:bg-neutral-600",
        className
      )}
    >
      <span
        className={cn(
          "absolute top-[3px] left-[3px] size-5 rounded-full shadow-xs transition-transform",
          checked ? "translate-x-[18px] bg-primary-foreground" : "bg-white"
        )}
      />
    </button>
  )
}

export { Switch }
