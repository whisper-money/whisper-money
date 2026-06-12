---
name: inertia-react-development
description: "Develops Inertia.js v2 React client-side applications. Activates when creating React pages, forms, or navigation; using <Link>, <Form>, useForm, or router; working with deferred props, prefetching, or polling; or when user mentions React with Inertia, React pages, React forms, or React navigation."
license: MIT
metadata:
  author: laravel
---

# Inertia React Development

## When to Apply

Activate this skill when:

- Creating or modifying React page components for Inertia
- Working with forms in React (using `<Form>` or `useForm`)
- Implementing client-side navigation with `<Link>` or `router`
- Using v2 features: deferred props, prefetching, WhenVisible, InfiniteScroll, once props, flash data, or polling
- Building React-specific features with the Inertia protocol

## Documentation

Use `search-docs` for detailed Inertia v2 React patterns and documentation.

## Basic Usage

### Page Components Location

React page components should be placed in the `resources/js/pages` directory.

### Page Component Structure

<!-- Basic React Page Component -->
```react
export default function UsersIndex({ users }) {
    return (
        <div>
            <h1>Users</h1>
            <ul>
                {users.map(user => <li key={user.id}>{user.name}</li>)}
            </ul>
        </div>
    )
}
```

## Client-Side Navigation

### Basic Link Component

Use `<Link>` for client-side navigation instead of traditional `<a>` tags:

<!-- Inertia React Navigation -->
```react
import { Link, router } from '@inertiajs/react'

<Link href="/">Home</Link>
<Link href="/users">Users</Link>
<Link href={`/users/${user.id}`}>View User</Link>
```

### Link with Method

<!-- Link with POST Method -->
```react
import { Link } from '@inertiajs/react'

<Link href="/logout" method="post" as="button">
    Logout
</Link>
```

### Prefetching

Prefetch pages to improve perceived performance:

<!-- Prefetch on Hover -->
```react
import { Link } from '@inertiajs/react'

<Link href="/users" prefetch>
    Users
</Link>
```

### Programmatic Navigation

<!-- Router Visit -->
```react
import { router } from '@inertiajs/react'

function handleClick() {
    router.visit('/users')
}

// Or with options
router.visit('/users', {
    method: 'post',
    data: { name: 'John' },
    onSuccess: () => console.log('Success!'),
})
```

## Form Handling

### Form Component (Recommended)

The recommended way to build forms is with the `<Form>` component:

<!-- Form Component Example -->
```react
import { Form } from '@inertiajs/react'

export default function CreateUser() {
    return (
        <Form action="/users" method="post">
            {({ errors, processing, wasSuccessful }) => (
                <>
                    <input type="text" name="name" />
                    {errors.name && <div>{errors.name}</div>}

                    <input type="email" name="email" />
                    {errors.email && <div>{errors.email}</div>}

                    <button type="submit" disabled={processing}>
                        {processing ? 'Creating...' : 'Create User'}
                    </button>

                    {wasSuccessful && <div>User created!</div>}
                </>
            )}
        </Form>
    )
}
```

### Form Component With All Props

<!-- Form Component Full Example -->
```react
import { Form } from '@inertiajs/react'

<Form action="/users" method="post">
    {({
        errors,
        hasErrors,
        processing,
        progress,
        wasSuccessful,
        recentlySuccessful,
        clearErrors,
        resetAndClearErrors,
        defaults,
        isDirty,
        reset,
        submit
    }) => (
        <>
            <input type="text" name="name" defaultValue={defaults.name} />
            {errors.name && <div>{errors.name}</div>}

            <button type="submit" disabled={processing}>
                {processing ? 'Saving...' : 'Save'}
            </button>

            {progress && (
                <progress value={progress.percentage} max="100">
                    {progress.percentage}%
                </progress>
            )}

            {wasSuccessful && <div>Saved!</div>}
        </>
    )}
</Form>
```

### Form Component Reset Props

The `<Form>` component supports automatic resetting:

- `resetOnError` - Reset form data when the request fails
- `resetOnSuccess` - Reset form data when the request succeeds
- `setDefaultsOnSuccess` - Update default values on success

Use the `search-docs` tool with a query of `form component resetting` for detailed guidance.

<!-- Form with Reset Props -->
```react
import { Form } from '@inertiajs/react'

<Form
    action="/users"
    method="post"
    resetOnSuccess
    setDefaultsOnSuccess
>
    {({ errors, processing, wasSuccessful }) => (
        <>
            <input type="text" name="name" />
            {errors.name && <div>{errors.name}</div>}

            <button type="submit" disabled={processing}>
                Submit
            </button>
        </>
    )}
</Form>
```

Forms can also be built using the `useForm` helper for more programmatic control. Use the `search-docs` tool with a query of `useForm helper` for guidance.

### `useForm` Hook

For more programmatic control or to follow existing conventions, use the `useForm` hook:

<!-- useForm Hook Example -->
```react
import { useForm } from '@inertiajs/react'

export default function CreateUser() {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
    })

    function submit(e) {
        e.preventDefault()
        post('/users', {
            onSuccess: () => reset('password'),
        })
    }

    return (
        <form onSubmit={submit}>
            <input
                type="text"
                value={data.name}
                onChange={e => setData('name', e.target.value)}
            />
            {errors.name && <div>{errors.name}</div>}

            <input
                type="email"
                value={data.email}
                onChange={e => setData('email', e.target.value)}
            />
            {errors.email && <div>{errors.email}</div>}

            <input
                type="password"
                value={data.password}
                onChange={e => setData('password', e.target.value)}
            />
            {errors.password && <div>{errors.password}</div>}

            <button type="submit" disabled={processing}>
                Create User
            </button>
        </form>
    )
}
```

## Inertia v2 Features

### Deferred Props

Use deferred props to load data after initial page render:

<!-- Deferred Props with Empty State -->
```react
export default function UsersIndex({ users }) {
    // users will be undefined initially, then populated
    return (
        <div>
            <h1>Users</h1>
            {!users ? (
                <div className="animate-pulse">
                    <div className="h-4 bg-gray-200 rounded w-3/4 mb-2"></div>
                    <div className="h-4 bg-gray-200 rounded w-1/2"></div>
                </div>
            ) : (
                <ul>
                    {users.map(user => (
                        <li key={user.id}>{user.name}</li>
                    ))}
                </ul>
            )}
        </div>
    )
}
```

### Polling

Automatically refresh data at intervals:

<!-- Polling Example -->
```react
import { router } from '@inertiajs/react'
import { useEffect } from 'react'

export default function Dashboard({ stats }) {
    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({ only: ['stats'] })
        }, 5000) // Poll every 5 seconds

        return () => clearInterval(interval)
    }, [])

    return (
        <div>
            <h1>Dashboard</h1>
            <div>Active Users: {stats.activeUsers}</div>
        </div>
    )
}
```

### WhenVisible

Lazy-load a prop when an element scrolls into view. Useful for deferring expensive data that sits below the fold:

<!-- WhenVisible Example -->
```react
import { WhenVisible } from '@inertiajs/react'

export default function Dashboard({ stats }) {
    return (
        <div>
            <h1>Dashboard</h1>

            {/* stats prop is loaded only when this section scrolls into view */}
            <WhenVisible data="stats" buffer={200} fallback={<div className="animate-pulse">Loading stats...</div>}>
                {({ fetching }) => (
                    <div>
                        <p>Total Users: {stats.total_users}</p>
                        <p>Revenue: {stats.revenue}</p>
                        {fetching && <span>Refreshing...</span>}
                    </div>
                )}
            </WhenVisible>
        </div>
    )
}
```

## Server-Side Patterns

Server-side patterns (Inertia::render, props, middleware) are covered in inertia-laravel guidelines.

## Common Pitfalls

- Using traditional `<a>` links instead of Inertia's `<Link>` component (breaks SPA behavior)
- Forgetting to add loading states (skeleton screens) when using deferred props
- Not handling the `undefined` state of deferred props before data loads
- Using `<form>` without preventing default submission (use `<Form>` component or `e.preventDefault()`)
- Forgetting to check if `<Form>` component is available in your Inertia version