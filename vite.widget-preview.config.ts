import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig, type Plugin } from 'vite';

/**
 * The widget preview MCP App (app/Mcp/Resources/WidgetPreview.php) as one
 * self-contained script and one stylesheet, which the resource inlines into
 * its HTML. The chat client draws it in a sandboxed iframe with no session
 * and a CSP that allows inline code only, so nothing may be split into
 * chunks or loaded from the app. Runs after the main build (`bun run build`),
 * which empties public/build first.
 */
/**
 * The app's self-hosted fonts would be inlined as megabytes of base64: the
 * preview takes the chat client's font instead.
 */
const withoutFonts: Plugin = {
    name: 'widget-preview-without-fonts',
    enforce: 'post',
    generateBundle(_, bundle) {
        for (const asset of Object.values(bundle)) {
            if (asset.type === 'asset' && asset.fileName.endsWith('.css')) {
                asset.source = String(asset.source).replace(
                    /@font-face\s*\{[^}]*\}/g,
                    '',
                );
            }
        }
    },
};

export default defineConfig({
    plugins: [withoutFonts, react(), tailwindcss()],
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    define: { 'process.env.NODE_ENV': '"production"' },
    build: {
        outDir: 'public/build/widget-preview',
        emptyOutDir: true,
        copyPublicDir: false,
        cssCodeSplit: false,
        sourcemap: false,
        chunkSizeWarningLimit: 4096,
        // Anything the CSS or the code references is inlined: the HTML is all
        // the iframe gets.
        assetsInlineLimit: Number.MAX_SAFE_INTEGER,
        rollupOptions: {
            input: 'resources/js/widgets/preview/main.tsx',
            output: {
                format: 'es',
                inlineDynamicImports: true,
                entryFileNames: 'widget-preview.js',
                assetFileNames: 'widget-preview[extname]',
            },
        },
    },
});
