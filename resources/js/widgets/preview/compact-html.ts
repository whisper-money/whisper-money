/**
 * A drawn widget's DOM as markup an agent can read in its context: the
 * structure, classes, texts, axis ticks and labels it drew, without what only
 * a browser needs. render_widget answers with it (scripts/render-widget.mjs).
 */

/** Elements that draw nothing an agent could review. */
const DROPPED_ELEMENTS = 'script, style, noscript, template, link, meta, defs';

/** Dropped when nothing is left inside: recharts draws a dozen empty layers. */
const DROPPED_WHEN_EMPTY = new Set(['g', 'title', 'desc']);

/**
 * Geometry attributes past this length are kept only as their length: the
 * shape is the browser's business. Shortened in the markup, not on the DOM,
 * where an invalid path is an error Chromium logs.
 */
const LONG_GEOMETRY = /\s(d|points)="([^"]{61,})"/g;

const MAX_STYLE_LENGTH = 160;

/** A run of same-looking siblings past this many is cut down. */
const MAX_SILENT_RUN = 4;

/** Siblings that carry text (ticks, rows) are kept longer: the text is the point. */
const MAX_TEXT_RUN = 24;

export interface CompactHtml {
    html: string;
    /** Whether `html` stops before the end of the widget. */
    truncated: boolean;
}

export function compactWidgetHtml(
    root: Element,
    maxLength: number,
): CompactHtml {
    const clone = root.cloneNode(true) as Element;

    clone.querySelectorAll(DROPPED_ELEMENTS).forEach((node) => node.remove());
    stripNoise(clone);
    dropEmpty(clone);
    [clone, ...clone.querySelectorAll('*')].forEach(collapseRuns);

    return cut(
        clone.outerHTML.replace(
            LONG_GEOMETRY,
            (_, name: string, value: string) =>
                ` ${name}="…${value.length} chars"`,
        ),
        maxLength,
    );
}

function dropEmpty(root: Element): void {
    // Deepest first, so a group that only held empty groups goes too.
    for (const element of [...root.querySelectorAll('*')].reverse()) {
        if (
            DROPPED_WHEN_EMPTY.has(element.tagName.toLowerCase()) &&
            element.childNodes.length === 0
        ) {
            element.remove();
        }
    }
}

function stripNoise(root: Element): void {
    const walker = root.ownerDocument.createTreeWalker(
        root,
        NodeFilter.SHOW_ELEMENT |
            NodeFilter.SHOW_TEXT |
            NodeFilter.SHOW_COMMENT,
    );
    const nodes: Node[] = [];

    for (
        let node: Node | null = walker.currentNode;
        node;
        node = walker.nextNode()
    ) {
        nodes.push(node);
    }

    for (const node of nodes) {
        if (node.nodeType === Node.ELEMENT_NODE) {
            compactAttributes(node as Element);
        } else if (
            node.nodeType === Node.COMMENT_NODE ||
            node.textContent?.trim() === ''
        ) {
            node.parentNode?.removeChild(node);
        }
    }
}

function compactAttributes(element: Element): void {
    for (const { name, value } of [...element.attributes]) {
        if (name.startsWith('xmlns')) {
            element.removeAttribute(name);
        } else if (value.startsWith('data:')) {
            element.setAttribute(name, 'data:…');
        } else if (name === 'style' && value.length > MAX_STYLE_LENGTH) {
            element.setAttribute(name, `${value.slice(0, MAX_STYLE_LENGTH)}…`);
        }
    }
}

function signature(element: Element): string {
    return `${element.tagName.toLowerCase()}.${element.getAttribute('class') ?? ''}`;
}

/**
 * Twelve bars of a chart read like one: past a few, same-looking siblings
 * become a comment saying how many were left out. Siblings with text keep
 * more of the run, since their text is what the agent checks.
 */
function collapseRuns(parent: Element): void {
    const children = [...parent.children];
    let start = 0;

    while (start < children.length) {
        let end = start + 1;

        while (
            end < children.length &&
            signature(children[end]) === signature(children[start])
        ) {
            end++;
        }

        const run = children.slice(start, end);
        const keep = run.some((child) => child.textContent?.trim())
            ? MAX_TEXT_RUN
            : MAX_SILENT_RUN;

        if (run.length > keep) {
            const dropped = run.slice(keep);
            const tag = signature(run[0]).replace(/\.$/, '');

            dropped[0].replaceWith(
                parent.ownerDocument.createComment(
                    ` ${dropped.length} more ${tag} `,
                ),
            );
            dropped.slice(1).forEach((child) => child.remove());
        }

        start = end;
    }
}

function cut(html: string, maxLength: number): CompactHtml {
    if (html.length <= maxLength) {
        return { html, truncated: false };
    }

    // Never stop halfway through a tag.
    const end = html.lastIndexOf('>', maxLength) + 1;

    return {
        html: `${html.slice(0, end)}<!-- cut: ${html.length - end} more characters -->`,
        truncated: true,
    };
}
