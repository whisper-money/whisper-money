import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { type FormEvent, type ReactNode } from 'react';

export type TokenScope = 'read' | 'read_write';

type FormLayout = 'inline' | 'stacked';

/**
 * A labelled select for one of the token's settings (its access, its expiry).
 */
export function TokenOptionSelect<T extends string>({
    id,
    label,
    value,
    onChange,
    options,
    layout = 'inline',
}: {
    id: string;
    label: string;
    value: T;
    onChange: (value: T) => void;
    options: { value: T; label: string }[];
    layout?: FormLayout;
}) {
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{label}</Label>
            {/* Radix Select renders a hidden native <select> next to the
                trigger; wrap it so the parent's space-y never pushes that
                hidden node below the trigger and breaks the row alignment. */}
            <div className={cn('w-full', layout === 'inline' && 'sm:w-48')}>
                <Select
                    value={value}
                    onValueChange={(next) => onChange(next as T)}
                >
                    <SelectTrigger id={id} className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {options.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
        </div>
    );
}

/**
 * The part of an Inertia `useForm` the token form drives. Pages pass their own
 * form, which may carry more fields (an expiry) rendered as `children`.
 */
export interface TokenFormState {
    data: { name: string; scope: TokenScope };
    setData(field: 'name' | 'scope', value: string): void;
    processing: boolean;
    errors: { name?: string };
}

/**
 * The form that creates a personal access token: a name and its access
 * (read only, or read & write). Extra settings, such as an expiry, go in
 * `children`, between the access and the submit button.
 */
export function CreateTokenForm({
    form,
    onSubmit,
    namePlaceholder,
    layout = 'inline',
    children,
}: {
    form: TokenFormState;
    onSubmit: (event: FormEvent) => void;
    namePlaceholder: string;
    layout?: FormLayout;
    children?: ReactNode;
}) {
    return (
        <>
            <form
                onSubmit={onSubmit}
                className={cn(
                    'flex flex-col gap-4',
                    layout === 'inline' && 'pt-2 sm:flex-row sm:items-end',
                )}
            >
                <div className="flex-1 space-y-2">
                    <Label htmlFor="token-name">{__('Name')}</Label>
                    <Input
                        id="token-name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        placeholder={namePlaceholder}
                        required
                    />
                </div>
                <TokenOptionSelect
                    id="token-scope"
                    label={__('Access')}
                    value={form.data.scope}
                    onChange={(scope) => form.setData('scope', scope)}
                    options={[
                        { value: 'read', label: __('Read only') },
                        { value: 'read_write', label: __('Read & write') },
                    ]}
                    layout={layout}
                />
                {children}
                <Button type="submit" disabled={form.processing}>
                    {__('Create token')}
                </Button>
            </form>
            {form.errors.name && (
                <p className="mt-2 text-sm text-destructive">
                    {form.errors.name}
                </p>
            )}
        </>
    );
}
