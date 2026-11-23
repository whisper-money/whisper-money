import js from '@eslint/js';
import prettier from 'eslint-config-prettier/flat';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import typescript from 'typescript-eslint';

/** @type {import('eslint').Linter.Config[]} */
export default [
    js.configs.recommended,
    reactHooks.configs.flat.recommended,
    ...typescript.configs.recommended,
    {
        ...react.configs.flat.recommended,
        ...react.configs.flat['jsx-runtime'], // Required for React 17+
        languageOptions: {
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            'react/react-in-jsx-scope': 'off',
            'react/prop-types': 'off',
            'react/no-unescaped-entities': 'off',
        },
        settings: {
            react: {
                version: 'detect',
            },
        },
    },
    {
        files: ['**/*.{ts,tsx}'],
        rules: {
            'react-hooks/set-state-in-effect': 'off',
            'react-hooks/static-components': 'off',
            'react-hooks/refs': 'off',
            'react-hooks/incompatible-library': 'off',
            'no-restricted-imports': [
                'error',
                {
                    patterns: [
                        {
                            group: ['@headlessui/*'],
                            message:
                                'Headless UI was dropped (#1365): use the Radix primitives in components/ui, or tw-animate-css / motion for transitions.',
                        },
                    ],
                },
            ],
        },
    },
    {
        // Semantic colors (#1358): the app colors meaning with the `success`,
        // `warning`, `destructive` and `muted-foreground` tokens, never a raw
        // green, red, emerald, rose or amber palette class.
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: [
            // Tests assert on class names, including the ones the guard bans.
            '**/*.test.{ts,tsx}',
            // Marketing and legal pages have their own look (docs/design-system.md).
            'resources/js/pages/{welcome,terms,privacy,comparison,integrations}.tsx',
            'resources/js/pages/documentation/**',
            'resources/js/components/{landing,documentation}/**',
            'resources/js/components/marketing-page.tsx',
            // The color a user picks for a category or a label is user data.
            'resources/js/types/category*.ts',
            'resources/js/types/label*.ts',
        ],
        rules: {
            'no-restricted-syntax': [
                'error',
                ...['Literal[value', 'TemplateElement[value.raw'].map(
                    (node) => ({
                        selector: `${node}=/-(green|red|emerald|rose|amber)-\\d/]`,
                        message:
                            'Use the semantic color tokens (docs/design-system.md, #1358): income and favourable trends `text-success`, unfavourable trends `text-muted-foreground`, expenses the foreground, warnings `warning`, errors and destructive actions `destructive`, confirmations `success`.',
                    }),
                ),
            ],
        },
    },
    {
        ignores: ['vendor', 'node_modules', 'public', 'bootstrap/ssr', 'tailwind.config.js'],
    },
    prettier, // Turn off all rules that might conflict with Prettier
];
