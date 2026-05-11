import { LabelBadges } from '@/components/shared/label-combobox';
import { Badge } from '@/components/ui/badge';
import { type AutomationRule, getRuleActions } from '@/types/automation-rule';
import { getCategoryColorClasses } from '@/types/category';
import { __ } from '@/utils/i18n';
import * as Icons from 'lucide-react';

export function AutomationRuleActionBadges({ rule }: { rule: AutomationRule }) {
    const actions = getRuleActions(rule);
    const hasLabels = actions.hasLabels && actions.labels?.length;

    if (!actions.category && !hasLabels && !actions.hasNote) {
        return (
            <span className="text-sm text-muted-foreground">
                {__('No action set')}
            </span>
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            {actions.category && <CategoryBadge category={actions.category} />}

            {hasLabels && <LabelBadges labels={actions.labels ?? []} max={5} />}

            {actions.hasNote && (
                <Badge variant="outline">{__('Add note')}</Badge>
            )}
        </div>
    );
}

function CategoryBadge({
    category,
}: {
    category: NonNullable<ReturnType<typeof getRuleActions>['category']>;
}) {
    const colorClasses = getCategoryColorClasses(category.color);
    const IconComponent = Icons[
        category.icon as keyof typeof Icons
    ] as Icons.LucideIcon;

    return (
        <Badge
            className={`${colorClasses.bg} ${colorClasses.text} flex items-center gap-2`}
        >
            {IconComponent && <IconComponent className="h-3 w-3 opacity-80" />}
            {category.name}
        </Badge>
    );
}
