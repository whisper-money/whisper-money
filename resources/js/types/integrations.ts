/** Copy for the integrations page, with the catalogue's own counts filled in. */
export type IntegrationsContent = {
    title: string;
    description: string;
    heading: string;
    intro: string;
    banks_title: string;
    banks_intro: string;
    banks_note: string;
    search_label: string;
    search_placeholder: string;
    matches: string;
    matches_one: string;
    empty_title: string;
    empty_body: string;
    providers_title: string;
    providers_intro: string;
    importer_title: string;
    importer_body: string;
    notes_title: string;
    notes: string[];
    closing_title: string;
    closing_body: string;
};

/** One country's slice of the catalogue, named in the page's language. */
export type IntegrationsCountry = {
    code: string;
    name: string;
    banks: string[];
};

/** The shared marketing chrome labels this page uses. */
export type IntegrationsLabels = {
    cta: string;
    pricing: string;
    back: string;
    other_language: string;
};

/** An integration that connects with an API key rather than over PSD2. */
export type IntegrationsProvider = {
    name: string;
    description: string;
};
