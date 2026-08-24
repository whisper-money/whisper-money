/**
 * A GET whose failures are failures.
 *
 * `fetch(...).then((r) => r.json())` does not reject on a 4xx or 5xx - `ok` is
 * the only thing that says so - and Laravel answers a JSON request with a JSON
 * body on the way down too. So a 500 used to parse cleanly into
 * `{message: "Server Error"}` and get spread into a chart's state, where every
 * figure it looked for was missing and rendered as zero. The user was shown
 * "you had 0 this period" for a request that never arrived, with nothing in the
 * console to say so.
 */
export async function fetchJson<T>(url: string): Promise<T> {
    const response = await fetch(url);

    if (!response.ok) {
        throw new Error(`GET ${url} answered ${response.status}`);
    }

    return (await response.json()) as T;
}
