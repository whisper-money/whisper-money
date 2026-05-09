/// <reference types="vite/client" />

interface ImportMetaEnv {
    readonly SENTRY_LARAVEL_DSN?: string;
    readonly VITE_APP_NAME?: string;
    readonly VITE_POSTHOG_ENABLED?: string;
    readonly VITE_POSTHOG_API_KEY?: string;
    readonly VITE_POSTHOG_HOST?: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}
