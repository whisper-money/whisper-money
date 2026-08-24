import { update } from '@/actions/App/Http/Controllers/Settings/LabelController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
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
import {
    getLabelColorClasses,
    LABEL_COLORS,
    type Label as LabelType,
} from '@/types/label';
import { __ } from '@/utils/i18n';
import { Form } from '@inertiajs/react';

interface EditLabelDialogProps {
    label: LabelType;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess?: () => void;
}

export function EditLabelDialog({
    label,
    open,
    onOpenChange,
    onSuccess,
}: EditLabelDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent hasKeyboard className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>{__('Edit Label')}</DialogTitle>
                    <DialogDescription>
                        {__('Update the label information.')}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...update.form.patch(label.id)}
                    onSuccess={() => {
                        onOpenChange(false);
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
                                    defaultValue={label.name}
                                    placeholder={__('Label name')}
                                    required
                                />

                                <InputError message={errors.name} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="color">{__('Color')}</Label>
                                <Select
                                    name="color"
                                    defaultValue={label.color}
                                    required
                                >
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
                                    onClick={() => onOpenChange(false)}
                                    disabled={processing}
                                >
                                    {__('Cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Updating...' : 'Update'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
