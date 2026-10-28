import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useIsMobile } from '@/hooks/use-mobile';
import { searchAutomationRules } from '@/lib/automation-rule-search';
import type { AutomationRule } from '@/types/automation-rule';
import { __ } from '@/utils/i18n';
import { ArrowLeft, Search } from 'lucide-react';
import { useMemo, useState } from 'react';

interface AutomationRulePickerProps {
    rules: AutomationRule[];
    onBack: () => void;
    onSelect: (rule: AutomationRule) => void;
}

/**
 * The rules to add a description to, with a search over their title and
 * category. The query lives here, so going back and returning starts over.
 */
export function AutomationRulePicker({
    rules,
    onBack,
    onSelect,
}: AutomationRulePickerProps) {
    const isMobile = useIsMobile();
    const [query, setQuery] = useState('');
    const visibleRules = useMemo(
        () => searchAutomationRules(rules, query),
        [rules, query],
    );

    return (
        <div className="space-y-3">
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="gap-2"
                onClick={onBack}
            >
                <ArrowLeft className="h-4 w-4" />
                {__('Back')}
            </Button>
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder={__('Search rules...')}
                    aria-label={__('Search rules...')}
                    autoFocus={!isMobile}
                    className="pl-9"
                />
            </div>
            <div className="max-h-80 space-y-2 overflow-y-auto">
                {visibleRules.length === 0 && (
                    <div className="py-6 text-center text-sm text-muted-foreground">
                        {__('No rules found.')}
                    </div>
                )}
                {visibleRules.map((rule) => (
                    <Button
                        key={rule.id}
                        type="button"
                        variant="outline"
                        className="h-auto w-full justify-start p-3 text-left"
                        onClick={() => onSelect(rule)}
                    >
                        <span>
                            <span className="block font-medium">
                                {rule.title}
                            </span>
                            <span className="block text-sm text-muted-foreground">
                                {rule.category?.name ?? __('No category set')}
                            </span>
                        </span>
                    </Button>
                ))}
            </div>
        </div>
    );
}
