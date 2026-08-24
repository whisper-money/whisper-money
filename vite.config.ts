import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import { sentryVitePlugin } from '@sentry/vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

// Only upload source maps when the build has a Sentry auth token (production
// CI). The same flag gates map generation, so a build without the token never
// emits .js.map files there is nothing to leak into public/build/assets/.
const sentryAuthToken = process.env.SENTRY_AUTH_TOKEN;

export default defineConfig({
    envPrefix: ['VITE_', 'SENTRY_LARAVEL_DSN'],
    build: {
        // 'hidden' emits maps for upload but omits the sourceMappingURL comment,
        // so browsers never fetch them even in the window before they're deleted.
        sourcemap: sentryAuthToken ? 'hidden' : false,
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
        ...(sentryAuthToken
            ? [
                  sentryVitePlugin({
                      url: 'https://de.sentry.io/',
                      authToken: sentryAuthToken,
                      org: 'whisper-money',
                      // Frontend JS errors are captured into the php-laravel
                      // project (see app.tsx Sentry.init using SENTRY_LARAVEL_DSN),
                      // so maps must be uploaded there to symbolicate them.
                      project: 'php-laravel',
                      release: {
                          create: false,
                          finalize: false,
                      },
                      sourcemaps: {
                          filesToDeleteAfterUpload: ['**/*.js.map'],
                      },
                  }),
              ]
            : []),
    ],
    esbuild: {
        jsx: 'automatic',
    },
});
