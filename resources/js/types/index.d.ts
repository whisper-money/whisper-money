import { InertiaLinkProps } from '@inertiajs/react';
import { LucideIcon } from 'lucide-react';
import { ReactNode } from 'react';
import { PricingConfig } from './pricing';
import { UUID } from './uuid';

export interface Auth {
    user: User;
    hasProPlan: boolean;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    type: 'nav-item';
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | ReactNode | null;
    isActive?: boolean;
}

export interface NavSectionHeader {
    type: 'section-header';
    title: string;
}

export interface NavDivider {
    type: 'divider';
}

export interface SharedData {
    name: string;
    appUrl: string;
    version: string;
    quote: { message: string; author: string };
    auth: Auth;
    subscriptionsEnabled: boolean;
    pricing: PricingConfig;
    sidebarOpen: boolean;
    [key: string]: unknown;
}

export interface User {
    id: UUID;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
}
