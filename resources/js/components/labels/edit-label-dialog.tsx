import { update } from '@/actions/App/Http/Controllers/Settings/LabelController';
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
import { labelSyncService } from '@/services/label-sync';
import {
    getLabelColorClasses,
    LABEL_COLORS,
    type Label as LabelType,
} from '@/types/label';
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
            <DialogContent className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>Edit Label</DialogTitle>
                    <DialogDescription>
                        Update the label information.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...update.form.patch(label.id)}
                    onSuccess={async () => {
                        await labelSyncService.sync();
                        onOpenChange(false);
                        onSuccess?.();
                    }}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={label.name}
                                    placeholder="Label name"
                                    required
                                />
                                {errors.name && (
                                    <p className="text-sm text-red-500">
                                        {errors.name}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="color">Color</Label>
                                <Select
                                    name="color"
                                    defaultValue={label.color}
                                    required
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a color" />
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
                                                            {color}
                                                        </Badge>
                                                    </div>
                                                </SelectItem>
                                            );
                                        })}
                                    </SelectContent>
                                </Select>
                                {errors.color && (
                                    <p className="text-sm text-red-500">
                                        {errors.color}
                                    </p>
                                )}
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                    disabled={processing}
                                >
                                    Cancel
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
