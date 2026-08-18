export type ComparisonRow = {
    dimension: string;
    rival: string;
    whisper: string;
};

export type ComparisonStep = {
    title: string;
    body: string;
};

/** A quote from a real user. There are no placeholders. */
export type ComparisonTestimonial = {
    name: string;
    gravatar?: string;
    avatar?: string;
    text: string;
};

export type ComparisonPageContent = {
    slug: string;
    rival: string;
    title: string;
    description: string;
    heading: string;
    intro: string;
    rows: ComparisonRow[];
    narrative_title: string;
    narrative: string[];
    bullets: string[];
    migration_intro: string;
    migration_steps: ComparisonStep[];
    testimonials: ComparisonTestimonial[];
    closing_body: string;
};

export type ComparisonLabels = {
    dimension: string;
    head_to_head: string;
    changes: string;
    migration: string;
    testimonials: string;
    closing: string;
    cta: string;
    pricing: string;
    back: string;
    other_language: string;
};
