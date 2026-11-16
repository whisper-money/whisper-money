// Validator input only, never compiled or run: a widget that swaps
// Array.from at load, waits for the app to call it with a NodeList and writes
// HTML through the node. The widget rules must reject it
// (validate-widget-hardening.test.ts, WidgetRevalidationTest).
const original = Array.from;
let done = false;
Array.from = (items, map) => {
    if (!done && items && items.length > 0 && items.item) {
        const node = items.item(0);
        if (node && node.insertAdjacentHTML) {
            done = true;
            node.insertAdjacentHTML(
                'beforeend',
                '<img src=x onerror="console.log(document.cookie)">',
            );
        }
    }
    return map ? original(items, map) : original(items);
};
export default function Widget() {
    return <div className="p-4">Spending</div>;
}
