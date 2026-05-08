/// <reference types="vite/client" />

interface ImportMetaEnv {
    readonly VITE_APP_NAME?: string;
    readonly VITE_POSTHOG_ENABLED?: string;
    readonly VITE_POSTHOG_API_KEY?: string;
    readonly VITE_POSTHOG_HOST?: string;
    readonly VITE_SENTRY_DSN?: string;
    readonly VITE_SENTRY_ENABLED?: string;
    readonly VITE_SENTRY_ENVIRONMENT?: string;
    readonly VITE_SENTRY_TRACES_SAMPLE_RATE?: string;
    readonly VITE_SENTRY_TRACE_PROPAGATION_TARGETS?: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}
