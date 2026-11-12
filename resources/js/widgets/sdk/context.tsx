import {
    type WidgetHeight,
    type WidgetWidth,
} from '@/components/dashboard/widgets/types';
import { type AccountType } from '@/types/account';
import { createContext, type ReactNode, useContext } from 'react';

/** What `useWidgetEnv()` tells a widget about where it is drawn. */
export interface WidgetEnv {
    theme: 'light' | 'dark';
    /** The width the widget has on the dashboard, as a fraction of the row. */
    width: WidgetWidth;
    height: WidgetHeight;
    /** True on a phone and at 1/3 width: room for a figure, not a table. */
    isCompact: boolean;
    privacyMode: boolean;
    /** The user's main currency, the one the API converts totals to. */
    currency: string;
    /** The region amounts and dates are written in, e.g. `es-ES`. */
    locale: string;
}

/** What `<BankLogo accountId>` needs to know about one of the user's accounts. */
export interface WidgetAccount {
    type: AccountType;
    bankName: string | null;
    bankLogo: string | null;
}

export interface WidgetSdkValue {
    /** The data the manifest declared, keyed by its names. */
    data: Readonly<Record<string, unknown>>;
    env: WidgetEnv;
    accounts: Readonly<Record<string, WidgetAccount>>;
}

const DEFAULT_VALUE: WidgetSdkValue = {
    data: {},
    env: {
        theme: 'light',
        width: '1/2',
        height: 'm',
        isCompact: false,
        privacyMode: false,
        currency: 'EUR',
        locale: 'en-US',
    },
    accounts: {},
};

const WidgetSdkContext = createContext<WidgetSdkValue>(DEFAULT_VALUE);

/** Hands one widget its data and environment; `<UserWidget>` renders it. */
export function WidgetSdkProvider({
    value,
    children,
}: {
    value: WidgetSdkValue;
    children: ReactNode;
}) {
    return (
        <WidgetSdkContext.Provider value={value}>
            {children}
        </WidgetSdkContext.Provider>
    );
}

export function useWidgetSdk(): WidgetSdkValue {
    return useContext(WidgetSdkContext);
}

/** The data the widget's manifest declared, already resolved by the app. */
export function useWidgetData(): Readonly<Record<string, unknown>> {
    return useWidgetSdk().data;
}

export function useWidgetEnv(): WidgetEnv {
    return useWidgetSdk().env;
}
