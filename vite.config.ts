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

// The plugin only logs a failed upload and lets the build pass, which ships
// bundles with debug IDs Sentry has never seen. The CI image build opts into
// failing instead; other builds (local, self-hosted) keep the lenient default.
const sentryUploadRequired = process.env.SENTRY_UPLOAD_REQUIRED === 'true';

export default defineConfig({
    envPrefix: ['VITE_', 'SENTRY_LARAVEL_DSN'],
    build: {
        // 'hidden' emits maps for upload but omits the sourceMappingURL comment,
        // so browsers never fetch them even in the window before they're deleted.
        sourcemap: sentryAuthToken ? 'hidden' : false,
    },
    plugins: [
        laravel({
            // widgets.css is the precompiled stylesheet for user widgets, loaded
            // only where they render (see resources/css/widgets.css).
            input: [
                'resources/css/app.css',
                'resources/css/widgets.css',
                'resources/js/app.tsx',
            ],
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
                      // CI creates and finalizes the release with sentry-cli;
                      // naming it here ties the uploaded maps to the same
                      // release instead of a git SHA the Docker build can't see.
                      release: {
                          name: process.env.SENTRY_RELEASE || undefined,
                          create: false,
                          finalize: false,
                      },
                      errorHandler: sentryUploadRequired
                          ? (error: Error): never => {
                                throw error;
                            }
                          : undefined,
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
