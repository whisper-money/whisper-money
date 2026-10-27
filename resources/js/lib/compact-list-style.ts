/**
 * 16px from the screen's edges below md, as design D sets the compact list:
 * out of the page's 24px side padding. Tied to the `p-6` of the pages that
 * list transactions (/transactions, account, budget, savings goal); a list
 * inside a card stays where the card puts it instead (`framedOnPhone`).
 */
export const PHONE_INSET = 'max-md:-mx-2';

/**
 * The compact list's frame below md: inset, with square bottom corners and no
 * bottom border, since the list runs on with the page (its last row draws the
 * closing hairline). Desktop-first on purpose, so the frame above md stays a
 * plain rounded box.
 */
export const PHONE_FRAME = `${PHONE_INSET} max-md:rounded-b-none max-md:border-b-0`;

/**
 * The compact list's secondary text (the line under a description, notes, a
 * day's total): design D's muted grey, a step darker than `muted-foreground`
 * so it still reads on the grey day band.
 */
export const COMPACT_LIST_MUTED_TEXT = 'text-[#636363] dark:text-[#a8a8a8]';
