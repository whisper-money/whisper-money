export type DocumentationHeading = {
    level: number;
    title: string;
    id: string;
    number: string;
};

export type DocumentationDocument = {
    slug: string;
    locale: string;
    title: string;
    description: string;
    html: string;
    headings: DocumentationHeading[];
    markdownUrl: string;
};

/**
 * A node of the sidebar tree. Sections group pages, pages can nest pages, and
 * `expanded` marks the branch the page being read lives in.
 */
export type DocumentationNavItem = {
    type: 'section' | 'page';
    key: string;
    title: string;
    slug?: string;
    url?: string;
    active: boolean;
    expanded: boolean;
    children: DocumentationNavItem[];
};

export type DocumentationSearchEntry = {
    slug: string;
    title: string;
    url: string;
    headings: { title: string; id: string }[];
};

export type DocumentationNeighbour = {
    title: string;
    url: string;
};

export type DocumentationLanguage = {
    locale: string;
    label: string;
    url: string;
    active: boolean;
};
