import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import { sentryVitePlugin } from '@sentry/vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const shouldUploadSourcemaps = Boolean(
        env.SENTRY_AUTH_TOKEN && env.SENTRY_ORG && env.SENTRY_PROJECT,
    );

    return {
        build: {
            sourcemap: shouldUploadSourcemaps,
        },
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.tsx'],
                ssr: 'resources/js/ssr.tsx',
                refresh: true,
            }),
            react({
                babel: {
                    plugins: ['babel-plugin-react-compiler'],
                },
            }),
            tailwindcss(),
            wayfinder({
                formVariants: true,
            }),
            ...(shouldUploadSourcemaps
                ? sentryVitePlugin({
                      authToken: env.SENTRY_AUTH_TOKEN,
                      org: env.SENTRY_ORG,
                      project: env.SENTRY_PROJECT,
                      release: {
                          name: env.SENTRY_RELEASE,
                          create: false,
                          finalize: false,
                      },
                      sourcemaps: {
                          filesToDeleteAfterUpload: ['**/*.js.map'],
                      },
                  })
                : []),
        ],
        esbuild: {
            jsx: 'automatic',
        },
    };
});
