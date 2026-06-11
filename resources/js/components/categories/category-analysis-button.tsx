import { CategoryAnalysisDrawer } from '@/components/categories/category-analysis-drawer';
import { Button } from '@/components/ui/button';
import { __ } from '@/utils/i18n';
import { ChartColumnBig } from 'lucide-react';
import { useState } from 'react';

interface CategoryAnalysisButtonProps {
    /** Distinct localStorage slot so each widget remembers its own category. */
    widgetKey: string;
    /** Category prefilled the first time this widget opens the drawer. */
    firstCategoryId?: string | null;
}

export function CategoryAnalysisButton({
    widgetKey,
    firstCategoryId,
}: CategoryAnalysisButtonProps) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button
                variant="ghost"
                size="sm"
                className="gap-1.5 text-muted-foreground"
                onClick={() => setOpen(true)}
            >
                <ChartColumnBig className="size-4" />
                {__('Analyze')}
            </Button>
            <CategoryAnalysisDrawer
                open={open}
                onOpenChange={setOpen}
                widgetKey={widgetKey}
                firstCategoryId={firstCategoryId}
            />
        </>
    );
}
