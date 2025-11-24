import { cn } from '@/lib/utils';
import { BirdIcon, SVGAttributes } from 'lucide-react';

export default function AppLogoIcon({ className }: { className?: SVGAttributes['className'] }) {
    return (
        <BirdIcon className={cn(
            "size-5 text-[#1b1b18] dark:text-[#EDEDEC]",
            className,
            'fill-transparent'
        )} />
    );
}
