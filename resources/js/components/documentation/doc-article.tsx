import { scrollToAnchor } from '@/lib/documentation';
import { useEffect, useRef } from 'react';

type MermaidModule = {
    default: {
        initialize: (options: { startOnLoad: boolean; theme: string }) => void;
        render: (id: string, definition: string) => Promise<{ svg: string }>;
    };
};

/**
 * The rendered page. Anchor clicks inside it scroll rather than reload, and any
 * mermaid block in the Markdown is drawn once the page is on screen.
 */
export default function DocArticle({ html }: { html: string }) {
    const articleRef = useRef<HTMLElement>(null);

    useEffect(() => {
        const article = articleRef.current;

        if (!article) {
            return;
        }

        const handleClick = (event: MouseEvent) => {
            const link = (event.target as HTMLElement | null)?.closest(
                'a[href^="#"]',
            );
            const hash = link?.getAttribute('href');

            if (!hash || hash === '#') {
                return;
            }

            if (scrollToAnchor(hash)) {
                event.preventDefault();
            }
        };

        article.addEventListener('click', handleClick);

        return () => article.removeEventListener('click', handleClick);
    }, [html]);

    useEffect(() => {
        let cancelled = false;

        async function renderMermaidDiagrams() {
            const article = articleRef.current;

            if (!article) {
                return;
            }

            const blocks = Array.from(
                article.querySelectorAll<HTMLElement>('code.language-mermaid'),
            );

            if (blocks.length === 0) {
                return;
            }

            const { default: mermaid } = (await import(
                /* @vite-ignore */ 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs'
            )) as MermaidModule;

            if (cancelled) {
                return;
            }

            mermaid.initialize({
                startOnLoad: false,
                theme: document.documentElement.classList.contains('dark')
                    ? 'dark'
                    : 'default',
            });

            await Promise.all(
                blocks.map(async (block, index) => {
                    const container = document.createElement('div');
                    const source = block.textContent ?? '';
                    const { svg } = await mermaid.render(
                        `documentation-mermaid-${index}-${crypto.randomUUID()}`,
                        source,
                    );

                    container.className = 'documentation-mermaid';
                    container.innerHTML = svg;
                    block.closest('pre')?.replaceWith(container);
                }),
            );
        }

        void renderMermaidDiagrams();

        return () => {
            cancelled = true;
        };
    }, [html]);

    return (
        <article
            ref={articleRef}
            className="max-w-3xl [&_.card]:rounded-xl [&_.card]:border [&_.card]:border-[#e3e3e0] [&_.card]:bg-black/[0.02] [&_.card]:p-5 dark:[&_.card]:border-[#3E3E3A] dark:[&_.card]:bg-white/[0.03] [&_.card_h3]:mt-0 [&_.card_h3]:mb-3 [&_.card_p]:mb-4 [&_.card_ul]:mb-0 [&_.cards-wrapper]:my-8 [&_.cards-wrapper]:grid [&_.cards-wrapper]:grid-cols-1 [&_.cards-wrapper]:gap-4 md:[&_.cards-wrapper]:grid-cols-2 [&_.documentation-mermaid]:my-6 [&_.documentation-mermaid]:overflow-x-auto [&_.documentation-mermaid]:p-5 [&_a]:font-medium [&_a]:text-[#1b1b18] [&_a]:underline dark:[&_a]:text-[#EDEDEC] [&_blockquote]:my-6 [&_blockquote]:rounded-xl [&_blockquote]:bg-black/[0.03] [&_blockquote]:px-5 [&_blockquote]:py-4 dark:[&_blockquote]:bg-white/[0.04] [&_blockquote_p]:mb-0 [&_code]:rounded [&_code]:bg-black/5 [&_code]:px-1.5 [&_code]:py-0.5 dark:[&_code]:bg-white/10 [&_h1]:mb-4 [&_h1]:text-4xl [&_h1]:leading-tight [&_h1]:font-semibold [&_h1]:tracking-tight [&_h1+p]:mb-8 [&_h1+p]:text-[17px] [&_h1+p]:leading-8 [&_h2]:mt-12 [&_h2]:mb-4 [&_h2]:scroll-mt-24 [&_h2]:border-t [&_h2]:border-[#e3e3e0] [&_h2]:pt-8 [&_h2]:text-2xl [&_h2]:font-semibold dark:[&_h2]:border-[#3E3E3A] [&_h3]:mt-8 [&_h3]:mb-3 [&_h3]:scroll-mt-24 [&_h3]:text-xl [&_h3]:font-semibold [&_li]:pl-1 [&_ol]:mb-5 [&_ol]:list-decimal [&_ol]:space-y-2 [&_ol]:pl-6 [&_p]:mb-5 [&_p]:leading-7 [&_p]:text-[#706f6c] dark:[&_p]:text-[#A1A09A] [&_pre]:my-6 [&_pre]:overflow-x-auto [&_pre]:rounded-xl [&_pre]:border [&_pre]:border-[#e3e3e0] [&_pre]:bg-black/[0.03] [&_pre]:p-5 [&_pre]:text-sm dark:[&_pre]:border-[#3E3E3A] dark:[&_pre]:bg-white/[0.04] [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_ul]:mb-5 [&_ul]:list-disc [&_ul]:space-y-2 [&_ul]:pl-6"
            dangerouslySetInnerHTML={{ __html: html }}
        />
    );
}
