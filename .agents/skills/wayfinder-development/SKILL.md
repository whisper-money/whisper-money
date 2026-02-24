---
name: wayfinder-development
description: "Activates whenever referencing backend routes in frontend components. Use when importing from @/actions or @/routes, calling Laravel routes from TypeScript, or working with Wayfinder route functions."
license: MIT
metadata:
  author: laravel
---

# Wayfinder Development

## When to Apply

Activate whenever referencing backend routes in frontend components:
- Importing from `@/actions/` or `@/routes/`
- Calling Laravel routes from TypeScript/JavaScript
- Creating links or navigation to backend endpoints

## Documentation

Use `search-docs` for detailed Wayfinder patterns and documentation.

## Quick Reference

### Generate Routes

Run after route changes if Vite plugin isn't installed:
```bash
php artisan wayfinder:generate --no-interaction
```
For form helpers, use `--with-form` flag:
```bash
php artisan wayfinder:generate --with-form --no-interaction
```

### Import Patterns

<!-- Controller Action Imports -->
```typescript
// Named imports for tree-shaking (preferred)...
import { show, store, update } from '@/actions/App/Http/Controllers/PostController'

// Named route imports...
import { show as postShow } from '@/routes/post'
```

### Common Methods

<!-- Wayfinder Methods -->
```typescript
// Get route object...
show(1) // { url: "/posts/1", method: "get" }

// Get URL string...
show.url(1) // "/posts/1"

// Specific HTTP methods...
show.get(1)
store.post()
update.patch(1)
destroy.delete(1)

// Form attributes for HTML forms...
store.form() // { action: "/posts", method: "post" }

// Query parameters...
show(1, { query: { page: 1 } }) // "/posts/1?page=1"
```

## Wayfinder + Inertia

Use Wayfinder with the `<Form>` component:
<!-- Wayfinder Form (React) -->
```typescript
<Form {...store.form()}><input name="title" /></Form>
```

## Verification

1. Run `php artisan wayfinder:generate` to regenerate routes if Vite plugin isn't installed
2. Check TypeScript imports resolve correctly
3. Verify route URLs match expected paths

## Common Pitfalls

- Using default imports instead of named imports (breaks tree-shaking)
- Forgetting to regenerate after route changes
- Not using type-safe parameter objects for route model binding