/**
 * The second line of defence behind the validator: before the first user
 * widget loads, the static side of every global a widget may name is made
 * read-only, so a widget that slipped past the validator still cannot swap
 * `Array.from`, `JSON.parse`, `Date.now` or `Math` itself for code of its own
 * that the app, React or a library would then call with their objects.
 *
 * - The global bindings (`globalThis.Math`, `globalThis.Date`…) become
 *   non-writable and non-configurable: `Math = {…}` cannot replace them.
 * - `Math`, `JSON` and `Intl`, and the constructors `Array`, `Object`,
 *   `Number`, `String`, `Boolean`, `Date`, `Map` and `Set`, are frozen, with
 *   every function they hold as an own property (the function objects, not
 *   what they return); for `Intl`, each constructor is frozen with its own
 *   statics too (`Intl.NumberFormat.supportedLocalesOf`).
 * - The methods every value shares are frozen as function objects: each
 *   function held by `Object.prototype`, `Array.prototype`,
 *   `Function.prototype`… and the iterator and Intl prototypes (accessors'
 *   get/set included). A widget reads them from any value it holds
 *   (`({}).hasOwnProperty`, `[].slice`), and an own property on one
 *   (`hasOwnProperty.call = …`) would make React or a library, which call
 *   `hasOwnProperty.call(obj, key)`, run widget code with their objects.
 *
 * The prototype objects themselves stay as they are, on purpose: freezing
 * them triggers the "override mistake" in third-party code (assigning
 * `toString` or `constructor` on an instance throws once the inherited one
 * is read-only), which would break the app around the widgets. Replacing a
 * method on a prototype needs the prototype, which is out of a widget's
 * reach: `prototype`, `constructor` and `__proto__` are forbidden property
 * names and `get()` only reads own properties. `constructor` is skipped here
 * for the same reason: it is only reached by that forbidden name, and
 * freezing `Error` or `Promise` would break code that sets
 * `Error.stackTraceLimit`.
 *
 * It runs from registerWidgetModules(), so pages without user widgets (and
 * users who never load one) are untouched; it is idempotent, and an object a
 * browser will not freeze is skipped rather than breaking registration.
 */

/** Globals whose members a widget may read (see validator/allowlist.ts). */
const SHARED_OBJECTS = [
    'Math',
    'JSON',
    'Intl',
    'Array',
    'Object',
    'Number',
    'String',
    'Boolean',
    'Date',
    'Map',
    'Set',
] as const;

/** Globals a widget may name whose binding a write would replace. */
const SHARED_BINDINGS = [
    ...SHARED_OBJECTS,
    'parseInt',
    'parseFloat',
    'isNaN',
    'isFinite',
] as const;

let hardened = false;

function freeze(target: object): void {
    try {
        if (!Object.isFrozen(target)) {
            Object.freeze(target);
        }
    } catch {
        // An exotic object that refuses: the rest still gets frozen.
    }
}

/** The functions `target` holds as own data properties, `prototype` aside. */
function ownFunctions(target: object): object[] {
    const functions: object[] = [];

    for (const key of Reflect.ownKeys(target)) {
        if (key === 'prototype') {
            continue;
        }

        const descriptor = Object.getOwnPropertyDescriptor(target, key);

        if (descriptor && typeof descriptor.value === 'function') {
            functions.push(descriptor.value as object);
        }
    }

    return functions;
}

/**
 * Freeze `target` and each function it holds; `depth` 2 also freezes the
 * functions those functions hold (Intl's constructors and their statics).
 */
function freezeStatics(target: unknown, depth: number): void {
    if (
        (typeof target !== 'object' && typeof target !== 'function') ||
        target === null
    ) {
        return;
    }

    try {
        for (const value of ownFunctions(target)) {
            if (depth > 1) {
                freezeStatics(value, depth - 1);
            } else {
                freeze(value);
            }
        }
    } catch {
        // Reading the descriptors failed: still freeze the object itself.
    }

    freeze(target);
}

/** Freeze every function `target` holds: values, getters and setters. */
function freezeOwnMethods(target: object): void {
    try {
        for (const key of Reflect.ownKeys(target)) {
            if (key === 'constructor') {
                continue;
            }

            const descriptor = Object.getOwnPropertyDescriptor(target, key);

            for (const method of [
                descriptor?.value,
                descriptor?.get,
                descriptor?.set,
            ]) {
                if (typeof method === 'function') {
                    freeze(method);
                }
            }
        }
    } catch {
        // An exotic prototype: the others still get done.
    }
}

/**
 * The prototypes whose methods a widget can read from a value it holds:
 * literals, what the safe globals build or return, iterators, async
 * functions and generators, errors it catches, Intl objects and what they
 * return.
 */
function reachablePrototypes(): object[] {
    const { getPrototypeOf } = Object;
    function* generator() {
        yield 0;
    }
    async function* asyncGenerator() {
        yield 0;
    }
    const sources: (() => unknown)[] = [
        () => Object.prototype,
        () => Array.prototype,
        () => String.prototype,
        () => Number.prototype,
        () => Boolean.prototype,
        () => BigInt.prototype,
        () => Symbol.prototype,
        () => Date.prototype,
        () => Map.prototype,
        () => Set.prototype,
        () => Function.prototype,
        () => RegExp.prototype,
        () => Promise.prototype,
        () => Error.prototype,
        () => getPrototypeOf([][Symbol.iterator]()),
        () => getPrototypeOf(getPrototypeOf([][Symbol.iterator]())),
        () => getPrototypeOf(''[Symbol.iterator]()),
        () => getPrototypeOf(new Map()[Symbol.iterator]()),
        () => getPrototypeOf(new Set()[Symbol.iterator]()),
        () => getPrototypeOf('a'.matchAll(/a/g)),
        () => getPrototypeOf(generator.prototype),
        () => getPrototypeOf(asyncGenerator.prototype),
        () => getPrototypeOf(getPrototypeOf(asyncGenerator.prototype)),
        () => getPrototypeOf(new Intl.Segmenter().segment('a')),
        () =>
            getPrototypeOf(
                new Intl.Segmenter().segment('a')[Symbol.iterator](),
            ),
        // Intl's members are not enumerable: Object.values() sees none.
        ...Reflect.ownKeys(Intl).map(
            (key) => () =>
                (
                    Intl as unknown as Record<
                        PropertyKey,
                        { prototype?: unknown }
                    >
                )[key]?.prototype,
        ),
    ];
    const prototypes: object[] = [];

    for (const source of sources) {
        try {
            const prototype = source();

            // Function.prototype is itself a function.
            if (
                (typeof prototype === 'object' && prototype !== null) ||
                typeof prototype === 'function'
            ) {
                prototypes.push(prototype);
            }
        } catch {
            // Not in this browser (an older Intl): nothing to reach.
        }
    }

    return prototypes;
}

function lockBinding(name: string): void {
    try {
        const descriptor = Object.getOwnPropertyDescriptor(globalThis, name);

        if (descriptor?.configurable && 'value' in descriptor) {
            Object.defineProperty(globalThis, name, {
                value: descriptor.value,
                writable: false,
                enumerable: descriptor.enumerable,
                configurable: false,
            });
        }
    } catch {
        // The host refuses: the frozen object below still holds.
    }
}

/** Make the static side of the shared globals read-only, once per page. */
export function hardenIntrinsics(): void {
    if (hardened) {
        return;
    }

    hardened = true;

    const global = globalThis as unknown as Record<string, unknown>;

    for (const name of SHARED_BINDINGS) {
        lockBinding(name);
    }

    for (const name of SHARED_OBJECTS) {
        freezeStatics(global[name], name === 'Intl' ? 2 : 1);
    }

    for (const prototype of reachablePrototypes()) {
        freezeOwnMethods(prototype);
    }
}

/** The globals hardenIntrinsics() freezes, for tests. */
export const HARDENED_GLOBALS: readonly string[] = SHARED_OBJECTS;
