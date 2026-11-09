import { Config } from '@remotion/cli/config';
import { enableTailwind } from '@remotion/tailwind-v4';
import path from 'node:path';

/**
 * Remotion bundles with webpack, so it needs the aliases Vite gives the app:
 * `@/` for resources/js and a stand-in for Inertia, whose real client expects
 * a page booted by the server. Run from the repo root (see package.json).
 */
const repoRoot = process.cwd();

Config.setPublicDir(path.join(repoRoot, 'videos/public'));
Config.setVideoImageFormat('jpeg');
Config.setJpegQuality(95);
Config.setCodec('h264');
Config.setCrf(18);
Config.setPixelFormat('yuv420p');

Config.overrideWebpackConfig((config) =>
    enableTailwind({
        ...config,
        resolve: {
            ...config.resolve,
            alias: {
                ...(config.resolve?.alias ?? {}),
                '@inertiajs/react$': path.join(
                    repoRoot,
                    'videos/src/system/inertia-mock.tsx',
                ),
                '@': path.join(repoRoot, 'resources/js'),
            },
        },
    }),
);
