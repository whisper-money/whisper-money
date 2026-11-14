import { __ } from '@/utils/i18n';
import { Marquee } from './marquee';
import {
    STARRED_BY_COMPANIES,
    type StarredByCompany,
} from './starred-by-companies';

interface StarredByStripProps {
    companies?: StarredByCompany[];
}

/**
 * Monochrome band of the companies whose people starred the repository on
 * GitHub, scrolling as a marquee that stands still under reduced motion.
 */
export function StarredByStrip({
    companies = STARRED_BY_COMPANIES,
}: StarredByStripProps) {
    const label = __('Starred on GitHub by people at');

    return (
        <section
            aria-label={label}
            className="flex w-full flex-col items-center gap-[22px] overflow-hidden border-y border-[#e3e3e0] py-10 sm:gap-7 sm:py-14 dark:border-[#3E3E3A]"
        >
            <p className="px-6 text-center font-mono text-[11px] font-medium tracking-[0.32em] text-[#706f6c] uppercase sm:text-[13px] dark:text-[#A1A09A]">
                {label}
            </p>
            <Marquee
                className="w-full [mask-image:linear-gradient(90deg,transparent_0,#000_56px,#000_calc(100%-56px),transparent_100%)] [--duration:45s] [--gap:44px] sm:[mask-image:linear-gradient(90deg,transparent_0,#000_160px,#000_calc(100%-160px),transparent_100%)] sm:[--gap:72px]"
                trackClassName="items-center text-[#706f6c] motion-reduce:[animation:none] dark:text-[#8f8e8a]"
            >
                {companies.map((company) => (
                    <div
                        key={company.name}
                        className="flex shrink-0 items-center gap-3 whitespace-nowrap"
                    >
                        {company.iconPath && (
                            <svg
                                viewBox="0 0 24 24"
                                fill="currentColor"
                                aria-hidden="true"
                                className="size-[22px] shrink-0 sm:size-7"
                            >
                                <path d={company.iconPath} />
                            </svg>
                        )}
                        <span className="text-[21px] leading-none font-medium tracking-[-0.01em] sm:text-[26px]">
                            {company.name}
                        </span>
                    </div>
                ))}
            </Marquee>
        </section>
    );
}
