import { scrollToAnchor } from '@/lib/documentation';
import { cn } from '@/lib/utils';
import { type DocumentationHeading } from '@/types/documentation';
import { useEffect, useState } from 'react';

type Props = {
    headings: DocumentationHeading[];
};

/**
 * Follows the reader down the page: the heading nearest the top of the viewport
 * is the one marked in the contents.
 */
function useActiveHeading(headings: DocumentationHeading[]): string {
    const [active, setActive] = useState('');

    useEffect(() => {
        const elements = headings
            .map((heading) => document.getElementById(heading.id))
            .filter((element): element is HTMLElement => element !== null);

        if (elements.length === 0) {
            return;
        }

        setActive(elements[0].id);

        const observer = new IntersectionObserver(
            (entries) => {
                const first = entries
                    .filter((entry) => entry.isIntersecting)
                    .sort(
                        (one, other) =>
                            one.boundingClientRect.top -
                            other.boundingClientRect.top,
                    )[0];

                if (first) {
                    setActive(first.target.id);
                }
            },
            { rootMargin: '-80px 0px -70% 0px' },
        );

        elements.forEach((element) => observer.observe(element));

        return () => observer.disconnect();
    }, [headings]);

    return active;
}

export default function DocToc({ headings }: Props) {
    const active = useActiveHeading(headings);

    if (headings.length === 0) {
        return null;
    }

    return (
        <ol className="m-0 flex list-none flex-col gap-0.5 border-l border-[#e3e3e0] p-0 dark:border-[#3E3E3A]">
            {headings.map((heading) => (
                <li key={heading.id}>
                    <a
                        href={`#${heading.id}`}
                        onClick={(event) => {
                            if (scrollToAnchor(heading.id)) {
                                event.preventDefault();
                            }
                        }}
                        className={cn(
                            '-ml-px flex gap-2 border-l-2 py-1.5 pl-3 text-sm no-underline transition-colors',
                            heading.level > 2 && 'pl-6',
                            heading.id === active
                                ? 'border-[#1b1b18] font-medium text-[#1b1b18] dark:border-[#EDEDEC] dark:text-[#EDEDEC]'
                                : 'border-transparent text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]',
                        )}
                    >
                        <span className="text-[#706f6c] dark:text-[#A1A09A]">
                            {heading.number}
                        </span>
                        <span>{heading.title}</span>
                    </a>
                </li>
            ))}
        </ol>
    );
}
