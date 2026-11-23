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
        // Semantic colors, part 1 (#1358): money in these folders is colored
        // with the `success` / `warning` / `muted-foreground` tokens, never a
        // raw green or red. t5b extends the list to the whole app plus amber.
        files: [
            'resources/js/components/accounts/**/*.{ts,tsx}',
            'resources/js/components/budgets/**/*.{ts,tsx}',
            'resources/js/components/cashflow/**/*.{ts,tsx}',
            'resources/js/components/charts/**/*.{ts,tsx}',
            'resources/js/components/dashboard/**/*.{ts,tsx}',
            'resources/js/components/savings-goals/**/*.{ts,tsx}',
            'resources/js/pages/Accounts/**/*.{ts,tsx}',
            'resources/js/pages/budgets/**/*.{ts,tsx}',
            'resources/js/pages/cashflow/**/*.{ts,tsx}',
            'resources/js/pages/dashboard.tsx',
            'resources/js/pages/monthly-summaries/**/*.{ts,tsx}',
            'resources/js/pages/savings-goals/**/*.{ts,tsx}',
            'resources/js/components/categories/category-analysis-drawer.tsx',
            'resources/js/components/onboarding/step-import-preview.tsx',
            'resources/js/components/transactions/import-step-broker-parts.tsx',
            'resources/js/components/transactions/transaction-analysis-drawer.tsx',
            'resources/js/components/transactions/transaction-base-columns.tsx',
            'resources/js/components/transactions/transaction-day-header.tsx',
            'resources/js/components/transactions/transaction-list-row.tsx',
            'resources/js/components/ui/amount-display.tsx',
            'resources/js/types/budget.ts',
            'resources/js/types/savings-goal.ts',
        ],
        ignores: [
            '**/*.test.{ts,tsx}',
            // Feedback and status colors, migrated in t5b.
            'resources/js/components/accounts/delete-account-dialog.tsx',
            'resources/js/components/dashboard/custom-dashboards/**',
        ],
        rules: {
            'no-restricted-syntax': [
                'error',
                ...['Literal[value', 'TemplateElement[value.raw'].map(
                    (node) => ({
                        selector: `${node}=/(text|bg|border|fill|stroke|ring|from|to|via|outline|divide|decoration|shadow|accent|color)-(green|red|emerald|rose)-\\d/]`,
                        message:
                            'Use the semantic color tokens (docs/design-system.md, #1358): income and favourable trends `text-success`, unfavourable trends `text-muted-foreground`, expenses the foreground, over budget `text-warning`, errors `destructive`.',
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
