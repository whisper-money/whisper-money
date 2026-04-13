import * as React from "react"
import * as PopoverPrimitive from "@radix-ui/react-popover"

import { cn } from "@/lib/utils"

function useSafeAreaTopPadding(): number {
  const [padding, setPadding] = React.useState(8)

  React.useEffect(() => {
    const updatePadding = () => {
      const value = Number.parseFloat(
        getComputedStyle(document.documentElement).getPropertyValue("--safe-area-top")
      )

      setPadding(Number.isNaN(value) ? 8 : Math.max(8, value + 8))
    }

    updatePadding()

    window.addEventListener("resize", updatePadding)
    window.visualViewport?.addEventListener("resize", updatePadding)

    return () => {
      window.removeEventListener("resize", updatePadding)
      window.visualViewport?.removeEventListener("resize", updatePadding)
    }
  }, [])

  return padding
}

function Popover({
  ...props
}: React.ComponentProps<typeof PopoverPrimitive.Root>) {
  return <PopoverPrimitive.Root data-slot="popover" {...props} />
}

function PopoverTrigger({
  ...props
}: React.ComponentProps<typeof PopoverPrimitive.Trigger>) {
  return <PopoverPrimitive.Trigger data-slot="popover-trigger" {...props} />
}

function PopoverContent({
  className,
  align = "center",
  sideOffset = 4,
  collisionPadding,
  ...props
}: React.ComponentProps<typeof PopoverPrimitive.Content>) {
  const safeAreaTopPadding = useSafeAreaTopPadding()

  return (
    <PopoverPrimitive.Portal>
      <PopoverPrimitive.Content
        data-slot="popover-content"
        align={align}
        sideOffset={sideOffset}
        collisionPadding={collisionPadding ?? { top: safeAreaTopPadding, right: 8, bottom: 8, left: 8 }}
        className={cn(
          "bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 w-72 origin-(--radix-popover-content-transform-origin) rounded-md border p-4 shadow-md outline-hidden",
          className
        )}
        {...props}
      />
    </PopoverPrimitive.Portal>
  )
}

function PopoverAnchor({
  ...props
}: React.ComponentProps<typeof PopoverPrimitive.Anchor>) {
  return <PopoverPrimitive.Anchor data-slot="popover-anchor" {...props} />
}

export { Popover, PopoverTrigger, PopoverContent, PopoverAnchor }
