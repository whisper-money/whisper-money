# Frontend Localization Guide

This document describes the localization system for the Whisper Money React frontend and the automated tools available.

## Overview

The application now supports multilingual UI text using a simple translation system:

- **Hook**: `useTranslations()` (aliased as `__()`)
- **Translation Files**: `lang/es.json` (Spanish), with English as fallback
- **Coverage**: 118+ React components have been localized
- **Total Strings**: 730+ translatable strings

## How It Works

### Backend (Laravel)

The `HandleInertiaRequests` middleware loads the appropriate translation file (`lang/{locale}.json`) and shares it with all Inertia page props:

```php
protected function getTranslations(): array
{
    $locale = app()->getLocale();
    $translationFile = lang_path("{$locale}.json");
    return json_decode(file_get_contents($translationFile), true) ?? [];
}
```

### Frontend (React)

Components use the `__()` function directly to translate strings:

```tsx
import { __ } from '@/utils/i18n';

export default function MyComponent() {
    return (
        <div>
            <h1>{__('Welcome to Whisper Money')}</h1>
            <Button>{__('Save')}</Button>
        </div>
    );
}
```

**Note**: The `__()` function uses React's `usePage()` hook internally, so it must be called within React components.

### Frontend (React)

Components use the `__()` hook to translate strings:

```tsx
import { __ } from '@/utils/i18n';

export default function MyComponent() {
    const t = __();

    return (
        <div>
            <h1>{t('Welcome to Whisper Money')}</h1>
            <Button>{t('Save')}</Button>
        </div>
    );
}
```

## Automated Localization Tools

### Main Localization Script (Local Development Only)

**File**: `scripts/localize-frontend.mjs` (not committed to repo)

This script automatically wraps hardcoded strings with the `t()` translation function across all React components.

**What it does**:

- Scans all `.tsx` and `.ts` files in `resources/js/`
- Wraps JSX text content and specific props (title, placeholder, etc.) with `t()`
- Auto-imports the `__()` hook and adds `const t = __()` to components
- Extracts all unique strings to `lang/es.json`
- Generates a detailed report of changes

**What it skips**:

- Technical identifiers (class names, data-test attributes, etc.)
- URLs, email addresses
- Currency codes (USD, EUR, etc.)
- Brand names (Whisper Money, Discord, etc.)
- Single-character strings
- Code-like patterns

**Usage** (local development only):

```bash
# Run the localization script (safe to run multiple times)
node scripts/localize-frontend.mjs

# Format the modified files
bun run format

# Review changes
git diff resources/js/
git diff lang/es.json
cat localization-report.txt
```

**Note**: The script is for local development only and not committed to the repository. Translations must be added manually to `lang/es.json`.

## Current Status

### Completed (✅)

- ✅ 118 React components localized
- ✅ 730 strings extracted and wrapped with `t()`
- ✅ Translation infrastructure in place
- ✅ Automated extraction tool created
- ✅ English fallbacks for all strings

### Pending (⏳)

- ⏳ Spanish translations (currently English placeholders - manual translation needed)
- ⏳ Some files may need manual review for complex JSX patterns

## Adding New Translatable Strings

### Option 1: Manual (Recommended for new code)

```tsx
import { __ } from '@/utils/i18n';

export default function NewComponent() {
    return (
        <div>
            <h1>{__('My New Title')}</h1>
            <Input placeholder={__('Enter your name')} />
        </div>
    );
}
```

Then add the translations to `lang/es.json`:

```json
{
    "My New Title": "Mi Nuevo Título",
    "Enter your name": "Ingresa tu nombre"
}
```

Then add the translations to `lang/es.json`:

```json
{
    "My New Title": "Mi Nuevo Título",
    "Enter your name": "Ingresa tu nombre"
}
```

### Option 2: Automated (For bulk updates - local development)

1. Write your component with hardcoded English strings
2. Run `node scripts/localize-frontend.mjs` (if available locally)
3. Run `bun run format`
4. Manually translate new entries in `lang/es.json`

## Translation with Placeholders

Support for dynamic values:

```tsx
// In your component
const message = __('Hello :name, you have :count unread messages', {
    name: user.name,
    count: unreadCount
});

// In lang/es.json
{
    "Hello :name, you have :count unread messages": "Hola :name, tienes :count mensajes sin leer"
}
```

## Testing Translations

1. **Change language**: Go to Settings → Language → Select "Español"
2. **Test UI**: Navigate through the app and verify translations
3. **Check console**: Look for missing translation warnings (if implemented)

## Translation Guidelines

### For Spanish (es)

- Use informal "tú" form (not formal "usted")
- Financial terms should be accurate and professional
- Preserve brand names in English
- Keep the friendly, approachable tone
- Use sentence case, not title case

### Examples

- ✅ "Guardar cambios" (Save changes)
- ❌ "Guardar Cambios" (wrong capitalization)
- ✅ "Iniciar sesión" (Log in)
- ❌ "Entrar" (too casual)

## CI/CD Integration

Consider adding translation validation to your CI pipeline in the future to ensure all strings are translated.

## Troubleshooting

### Issue: Translations not showing

**Solution**: Check that:

1. The string exists in `lang/es.json`
2. User's language setting is "Español" or auto-detected to Spanish
3. Browser cache is cleared

### Issue: String wrapped incorrectly by script

**Solution**:

1. Revert the file: `git checkout <file>`
2. Manually wrap the string
3. Add the string pattern to skip list in `scripts/localize-frontend.mjs`

### Issue: Script breaks complex JSX

**Solution**: The script has limitations with very complex nested JSX. For these files:

1. Revert the auto-changes
2. Manually localize
3. Or simplify the JSX structure

## File Structure

```
lang/
├── es.json              # Spanish translations
├── es/                  # Laravel backend translations (PHP)
│   ├── auth.php
│   ├── pagination.php
│   └── ...
└── en/                  # English backend translations

scripts/
├── localize-frontend.mjs       # Main localization tool
└── translate-to-spanish.mjs    # AI translation helper

resources/js/
├── utils/i18n.ts              # Translation hook
└── types/index.d.ts           # SharedData type (includes translations)
```

## Next Steps

1. **Manual translation**: Translate English placeholders in `lang/es.json` to Spanish
2. **Review translations**: Check accuracy, tone, and proper Spanish conventions
3. **Test thoroughly**: Test the entire app in Spanish
4. **Add more languages**: Create `lang/fr.json`, `lang/de.json`, etc.

## Resources

- Translation hook: `resources/js/utils/i18n.ts`
- Example localized component: `resources/js/pages/auth/login.tsx`
- Example localized settings: `resources/js/pages/settings/account.tsx`
- Translation middleware: `app/Http/Middleware/HandleInertiaRequests.php`
- Spanish translations: `lang/es.json`

## Development Scripts

The localization automation script (`scripts/localize-frontend.mjs`) is available for local development only and is not committed to the repository. It uses Babel to parse and transform React components automatically. If you need to regenerate it, refer to the project's localization implementation history.
