import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import { sentryVitePlugin } from '@sentry/vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    build: {
        sourcemap: true,
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
        sentryVitePlugin({
            url: 'https://bugsink.whisper.money/',
            authToken: 'b0f46d1bdc25b9a998f540542e027e9faf0a2e8a',
            org: 'bugsinkhasnoorgs',
            project: 'ignoredfornow',
            release: {
                create: false,
                finalize: false,
            },
            sourcemaps: {
                filesToDeleteAfterUpload: ['**/*.js.map'],
            },
        }),
    ],
    esbuild: {
        jsx: 'automatic',
    },
});
