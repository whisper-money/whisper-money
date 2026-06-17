import { IntegrationRequestsBoard } from '@/components/integration-requests/integration-requests-board';
import {
    Drawer,
    DrawerContent,
    DrawerDescription,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import { __ } from '@/utils/i18n';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function IntegrationRequestsDrawer({ open, onOpenChange }: Props) {
    return (
        <Drawer open={open} onOpenChange={onOpenChange}>
            <DrawerContent className="h-[95vh] data-[vaul-drawer-direction=bottom]:max-h-[95vh]">
                <div className="mx-auto w-full max-w-2xl overflow-y-auto p-6">
                    <DrawerHeader className="px-0">
                        <DrawerTitle>{__('Integration requests')}</DrawerTitle>
                        <DrawerDescription>
                            {__(
                                'Request a bank or service and vote for the ones you want us to add next.',
                            )}
                        </DrawerDescription>
                    </DrawerHeader>

                    <IntegrationRequestsBoard />
                </div>
            </DrawerContent>
        </Drawer>
    );
}
