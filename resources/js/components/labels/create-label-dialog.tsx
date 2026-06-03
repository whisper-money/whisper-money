import { store } from '@/actions/App/Http/Controllers/Settings/LabelController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CreateButton } from '@/components/ui/create-button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { getLabelColorClasses, LABEL_COLORS } from '@/types/label';
import { __ } from '@/utils/i18n';
import { Form } from '@inertiajs/react';
import { useState } from 'react';

export function CreateLabelDialog({ onSuccess }: { onSuccess?: () => void }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <CreateButton>{__('Create Label')}</CreateButton>
            </DialogTrigger>
            <DialogContent hasKeyboard className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>{__('Create Label')}</DialogTitle>
                    <DialogDescription>
                        {__('Add a new label to tag your transactions.')}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...store.form()}
                    onSuccess={() => {
                        setOpen(false);
                        onSuccess?.();
                    }}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="name">{__('Name')}</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    placeholder={__('Label name')}
                                    required
                                />

                                <InputError message={errors.name} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="color">{__('Color')}</Label>
                                <Select name="color" required>
                                    <SelectTrigger>
                                        <SelectValue
                                            placeholder={__('Select a color')}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {LABEL_COLORS.map((color) => {
                                            const colorClasses =
                                                getLabelColorClasses(color);
                                            return (
                                                <SelectItem
                                                    key={color}
                                                    value={color}
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <Badge
                                                            className={`${colorClasses.bg} ${colorClasses.text}`}
                                                        >
                                                            {__(color)}
                                                        </Badge>
                                                    </div>
                                                </SelectItem>
                                            );
                                        })}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.color} />
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpen(false)}
                                    disabled={processing}
                                >
                                    {__('Cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Creating...' : 'Create'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
