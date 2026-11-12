import {
    store,
    update,
} from '@/actions/App/Http/Controllers/Dashboards/DashboardManagementController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { trackDashboardCreated } from '@/lib/custom-dashboards-telemetry';
import { type DashboardSummary } from '@/types/dashboard';
import { __ } from '@/utils/i18n';
import { Form } from '@inertiajs/react';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The dashboard to rename; without one the dialog creates a dashboard. */
    dashboard?: Pick<DashboardSummary, 'id' | 'name'>;
}

/**
 * Names a new dashboard, which the server then opens, or renames one. A new
 * dashboard starts empty.
 */
export function DashboardNameDialog({ open, onOpenChange, dashboard }: Props) {
    const form = dashboard ? update.form.patch(dashboard.id) : store.form();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent hasKeyboard className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>
                        {dashboard
                            ? __('Rename dashboard')
                            : __('New dashboard')}
                    </DialogTitle>
                    <DialogDescription>
                        {dashboard
                            ? __(
                                  'Give the dashboard a name you will recognise.',
                              )
                            : __(
                                  'It starts empty: add the widgets you want next.',
                              )}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        if (!dashboard) {
                            trackDashboardCreated();
                        }
                        onOpenChange(false);
                    }}
                    className="flex flex-col gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="dashboard-name">
                                    {__('Name')}
                                </Label>
                                <Input
                                    id="dashboard-name"
                                    name="name"
                                    defaultValue={dashboard?.name}
                                    placeholder={__('e.g. Monthly review')}
                                    maxLength={60}
                                    required
                                    autoFocus
                                />
                                <InputError message={errors.name} />
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                    disabled={processing}
                                >
                                    {__('Cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {dashboard ? __('Save') : __('Create')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
