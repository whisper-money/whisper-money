/**
 * Edge to edge below md, like a phone list: out of the page's 24px side
 * padding and without side borders or corners. Tied to the `p-6` of the pages
 * that list transactions (/transactions, account, budget, savings goal); a
 * list inside a card keeps its frame instead (`framedOnPhone`). Desktop-first
 * on purpose, so the framed classes above md stay as they are.
 */
export const PHONE_BLEED = 'max-md:-mx-6 max-md:rounded-none max-md:border-x-0';
