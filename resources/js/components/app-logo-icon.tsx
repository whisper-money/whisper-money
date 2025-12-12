import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { cn } from '@/lib/utils';
import { BirdIcon, Birdhouse, SVGAttributes } from 'lucide-react';

export default function AppLogoIcon({
    className,
    animated = false,
}: {
    className?: SVGAttributes['className'];
    animated?: boolean;
}) {
    const { isKeySet } = useEncryptionKey();

    const iconClasses = cn(
        'size-5 text-[#1b1b18] dark:text-[#EDEDEC]',
        className,
        'fill-transparent',
    );

    if (!animated) {
        return <BirdIcon className={iconClasses} />;
    }

    return (
        <div className="relative size-5">
            <BirdIcon
                className={cn(
                    iconClasses,
                    'absolute inset-0 transition-all duration-300',
                    isKeySet ? 'scale-100 opacity-100' : 'scale-75 opacity-0',
                )}
            />
            <Birdhouse
                className={cn(
                    iconClasses,
                    'absolute inset-0 transition-all duration-300',
                    isKeySet ? 'scale-75 opacity-0' : 'scale-100 opacity-100',
                )}
            />
        </div>
    );
}
