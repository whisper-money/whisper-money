import {
    CarIcon,
    ClapperboardIcon,
    CoinsIcon,
    HeartPulseIcon,
    type LucideIcon,
    ServerIcon,
    ShoppingBasketIcon,
} from 'lucide-react';
import { useVideo } from '../context';

/** Category colors match the app's category palette (tailwind 500/400). */
const CATEGORY_STYLES: { icon: LucideIcon; light: string; dark: string }[] = [
    { icon: CoinsIcon, light: '#16a34a', dark: '#22c55e' },
    { icon: ShoppingBasketIcon, light: '#dc2626', dark: '#ef4444' },
    { icon: ServerIcon, light: '#c026d3', dark: '#d946ef' },
    { icon: CarIcon, light: '#2563eb', dark: '#3b82f6' },
    { icon: ClapperboardIcon, light: '#7c3aed', dark: '#8b5cf6' },
    { icon: HeartPulseIcon, light: '#e11d48', dark: '#f43f5e' },
];

export function CategoryBadge({ index, name }: { index: number; name: string }) {
    const { theme } = useVideo();
    const style = CATEGORY_STYLES[index % CATEGORY_STYLES.length];
    const Icon = style.icon;

    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
            <div
                style={{
                    width: 36,
                    height: 36,
                    borderRadius: 999,
                    background: theme.name === 'dark' ? style.dark : style.light,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    color: '#ffffff',
                }}
            >
                <Icon size={19} strokeWidth={2.2} />
            </div>
            <span style={{ fontSize: 22, fontWeight: 500 }}>{name}</span>
        </div>
    );
}
