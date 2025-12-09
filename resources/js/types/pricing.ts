export interface Plan {
    name: string;
    price: number;
    original_price: number | null;
    stripe_price_id: string | null;
    billing_period: 'month' | 'year' | null;
    features: string[];
}

export interface PromoConfig {
    enabled: boolean;
    code: string;
    description: string;
    badge: string;
}

export interface PricingConfig {
    plans: Record<string, Plan>;
    defaultPlan: string;
    bestValuePlan: string | null;
    promo: PromoConfig;
}
