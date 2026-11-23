import {
    Specimen,
    type PreviewEntry,
} from '@/components/design-system/showcase';
import { AiSparkleIcon } from '@/components/ui/ai-sparkle-icon';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { AmountInput } from '@/components/ui/amount-input';
import { AnimatedCollapse } from '@/components/ui/animated-collapse';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { CreateButton } from '@/components/ui/create-button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Drawer,
    DrawerContent,
    DrawerDescription,
    DrawerHeader,
    DrawerTitle,
    DrawerTrigger,
} from '@/components/ui/drawer';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Kbd, KbdGroup } from '@/components/ui/kbd';
import { Label } from '@/components/ui/label';
import { MultiSelect } from '@/components/ui/multi-select';
import { PasswordInput } from '@/components/ui/password-input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Progress } from '@/components/ui/progress';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { Toggle } from '@/components/ui/toggle';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { AlertTriangle, Bold, Info, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';

const BUTTON_VARIANTS = [
    'default',
    'secondary',
    'outline',
    'ghost',
    'link',
    'destructive',
] as const;

const BUTTON_SIZES = ['sm', 'default', 'lg'] as const;

function Buttons() {
    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-3">
                {BUTTON_VARIANTS.map((variant) => (
                    <Specimen key={variant} label={variant}>
                        <Button variant={variant}>Save</Button>
                    </Specimen>
                ))}
            </div>
            <div className="flex flex-wrap items-end gap-3">
                {BUTTON_SIZES.map((size) => (
                    <Specimen key={size} label={`size=${size}`}>
                        <Button size={size} variant="outline">
                            Save
                        </Button>
                    </Specimen>
                ))}
                <Specimen label="size=icon">
                    <Button size="icon" variant="outline" aria-label="Edit">
                        <Pencil />
                    </Button>
                </Specimen>
                <Specimen label="CreateButton">
                    <CreateButton>New budget</CreateButton>
                </Specimen>
                <Specimen label="Kbd in a button">
                    <Button>
                        Save <Kbd>⌘⏎</Kbd>
                    </Button>
                </Specimen>
                <Specimen label="ButtonGroup">
                    <ButtonGroup>
                        <Button variant="outline">Day</Button>
                        <Button variant="outline">Week</Button>
                        <Button variant="outline">Month</Button>
                    </ButtonGroup>
                </Specimen>
                <Specimen label="disabled">
                    <Button disabled>Save</Button>
                </Specimen>
            </div>
        </div>
    );
}

const BADGE_VARIANTS = [
    'default',
    'secondary',
    'outline',
    'destructive',
    'success',
    'warning',
] as const;

function Badges() {
    return BADGE_VARIANTS.map((variant) => (
        <Specimen key={variant} label={variant}>
            <Badge variant={variant}>Groceries</Badge>
        </Specimen>
    ));
}

function Alerts() {
    return (
        <div className="grid w-full gap-3 md:grid-cols-2">
            <Alert>
                <Info />
                <AlertTitle>Import finished</AlertTitle>
                <AlertDescription>
                    124 transactions were added.
                </AlertDescription>
            </Alert>
            <Alert variant="destructive">
                <AlertTriangle />
                <AlertTitle>The bank connection expired</AlertTitle>
                <AlertDescription>
                    Reconnect it to keep syncing.
                </AlertDescription>
            </Alert>
            <Alert variant="warning">
                <AlertTriangle />
                <AlertTitle>Your connection expires in 3 days</AlertTitle>
                <AlertDescription>
                    Renew the consent before it stops syncing.
                </AlertDescription>
            </Alert>
        </div>
    );
}

function Cards() {
    return (
        <Card className="w-full max-w-sm">
            <CardHeader>
                <CardTitle>Monthly budget</CardTitle>
                <CardDescription>Groceries · November</CardDescription>
            </CardHeader>
            <CardContent>
                <Progress value={64} />
            </CardContent>
            <CardFooter className="justify-end">
                <Button variant="outline" size="sm">
                    Details
                </Button>
            </CardFooter>
        </Card>
    );
}

const SAMPLE_CATEGORIES = [
    { value: 'groceries', label: 'Groceries' },
    { value: 'rent', label: 'Rent' },
    { value: 'travel', label: 'Travel' },
];

function FormControls() {
    const [amount, setAmount] = useState(4250);
    const [categories, setCategories] = useState<string[]>(['groceries']);

    return (
        <div className="grid w-full gap-4 md:grid-cols-2">
            <div className="flex flex-col gap-2">
                <Label htmlFor="ds-input">Input</Label>
                <Input id="ds-input" placeholder="Description" />
            </div>
            <div className="flex flex-col gap-2">
                <Label htmlFor="ds-amount">AmountInput</Label>
                <AmountInput
                    id="ds-amount"
                    value={amount}
                    onChange={(valueInCents) => setAmount(valueInCents)}
                    currencyCode="EUR"
                />
            </div>
            <div className="flex flex-col gap-2">
                <Label htmlFor="ds-password">PasswordInput</Label>
                <PasswordInput id="ds-password" defaultValue="hunter22" />
            </div>
            <div className="flex flex-col gap-2">
                <Label>Select</Label>
                <Select defaultValue="groceries">
                    <SelectTrigger className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {SAMPLE_CATEGORIES.map(({ value, label }) => (
                            <SelectItem key={value} value={value}>
                                {label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <div className="flex flex-col gap-2">
                <Label>MultiSelect</Label>
                <MultiSelect
                    options={SAMPLE_CATEGORIES}
                    selected={categories}
                    onChange={setCategories}
                />
            </div>
            <div className="flex flex-col gap-2">
                <Label htmlFor="ds-textarea">Textarea</Label>
                <Textarea id="ds-textarea" placeholder="Notes" />
            </div>
            <div className="flex items-center gap-2">
                <Checkbox id="ds-checkbox" defaultChecked />
                <Label htmlFor="ds-checkbox">Checkbox</Label>
            </div>
            <RadioGroup defaultValue="monthly" className="flex gap-4">
                {['weekly', 'monthly'].map((period) => (
                    <div key={period} className="flex items-center gap-2">
                        <RadioGroupItem value={period} id={`ds-${period}`} />
                        <Label htmlFor={`ds-${period}`}>{period}</Label>
                    </div>
                ))}
            </RadioGroup>
            <div className="flex items-center gap-3">
                <Toggle aria-label="Bold">
                    <Bold />
                </Toggle>
                <ToggleGroup type="single" variant="outline" defaultValue="m">
                    <ToggleGroupItem value="w">Week</ToggleGroupItem>
                    <ToggleGroupItem value="m">Month</ToggleGroupItem>
                    <ToggleGroupItem value="y">Year</ToggleGroupItem>
                </ToggleGroup>
            </div>
        </div>
    );
}

function Feedback() {
    const [open, setOpen] = useState(false);

    return (
        <div className="flex w-full flex-col gap-4">
            <Specimen label="Progress">
                <Progress value={40} className="h-2" />
            </Specimen>
            <div className="flex flex-wrap items-center gap-6">
                <Specimen label="Skeleton">
                    <Skeleton className="h-4 w-40" />
                </Specimen>
                <Specimen label="Spinner">
                    <Spinner />
                </Specimen>
                <Specimen label="KbdGroup">
                    <KbdGroup>
                        <Kbd>⌘</Kbd>
                        <Kbd>K</Kbd>
                    </KbdGroup>
                </Specimen>
                <Specimen label="AiSparkleIcon">
                    <AiSparkleIcon className="size-5" />
                </Specimen>
                <Specimen label="Avatar">
                    <Avatar>
                        <AvatarFallback>WM</AvatarFallback>
                    </Avatar>
                </Specimen>
            </div>
            <Specimen label="AnimatedCollapse">
                <Button
                    variant="outline"
                    size="sm"
                    className="w-fit"
                    onClick={() => setOpen((current) => !current)}
                >
                    {open ? 'Hide' : 'Show'} details
                </Button>
                <AnimatedCollapse open={open}>
                    <p className="pt-2 text-sm text-muted-foreground">
                        Collapsed content.
                    </p>
                </AnimatedCollapse>
            </Specimen>
        </div>
    );
}

function Overlays() {
    return (
        <>
            <Dialog>
                <DialogTrigger asChild>
                    <Button variant="outline">Dialog</Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit transaction</DialogTitle>
                        <DialogDescription>Sample dialog.</DialogDescription>
                    </DialogHeader>
                    <Input placeholder="Description" />
                    <DialogFooter>
                        <Button>Save</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <AlertDialog>
                <AlertDialogTrigger asChild>
                    <Button variant="outline">
                        <Trash2 /> AlertDialog
                    </Button>
                </AlertDialogTrigger>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete this budget?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Sample confirmation.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction>Delete</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            <Sheet>
                <SheetTrigger asChild>
                    <Button variant="outline">Sheet</Button>
                </SheetTrigger>
                <SheetContent>
                    <SheetHeader>
                        <SheetTitle>Filters</SheetTitle>
                        <SheetDescription>Sample sheet.</SheetDescription>
                    </SheetHeader>
                </SheetContent>
            </Sheet>
            <Drawer>
                <DrawerTrigger asChild>
                    <Button variant="outline">Drawer</Button>
                </DrawerTrigger>
                <DrawerContent>
                    <DrawerHeader>
                        <DrawerTitle>Pick a category</DrawerTitle>
                        <DrawerDescription>Sample drawer.</DrawerDescription>
                    </DrawerHeader>
                </DrawerContent>
            </Drawer>
            <Popover>
                <PopoverTrigger asChild>
                    <Button variant="outline">Popover</Button>
                </PopoverTrigger>
                <PopoverContent className="text-sm">
                    Sample popover.
                </PopoverContent>
            </Popover>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="outline">DropdownMenu</Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                    <DropdownMenuLabel>Transaction</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem>Edit</DropdownMenuItem>
                    <DropdownMenuItem variant="destructive">
                        Delete
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button variant="outline">Tooltip</Button>
                </TooltipTrigger>
                <TooltipContent>Sample tooltip</TooltipContent>
            </Tooltip>
        </>
    );
}

const SAMPLE_ROWS = [
    { id: 1, description: 'Supermarket', category: 'Groceries' },
    { id: 2, description: 'Monthly rent', category: 'Rent' },
];

function NavigationAndData() {
    return (
        <div className="flex w-full flex-col gap-4">
            <Breadcrumb>
                <BreadcrumbList>
                    <BreadcrumbItem>Settings</BreadcrumbItem>
                    <BreadcrumbSeparator />
                    <BreadcrumbItem>
                        <BreadcrumbPage>Categories</BreadcrumbPage>
                    </BreadcrumbItem>
                </BreadcrumbList>
            </Breadcrumb>
            <Separator />
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Description</TableHead>
                        <TableHead>Category</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {SAMPLE_ROWS.map((row) => (
                        <TableRow key={row.id}>
                            <TableCell>{row.description}</TableCell>
                            <TableCell>{row.category}</TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

export const PRIMITIVE_PREVIEWS: PreviewEntry[] = [
    { id: 'buttons', title: 'Buttons', Component: Buttons },
    { id: 'badges', title: 'Badges', Component: Badges },
    { id: 'alerts', title: 'Alerts', Component: Alerts },
    { id: 'cards', title: 'Cards', Component: Cards },
    { id: 'form-controls', title: 'Form controls', Component: FormControls },
    { id: 'feedback', title: 'Feedback', Component: Feedback },
    { id: 'overlays', title: 'Overlays', Component: Overlays },
    {
        id: 'navigation-and-data',
        title: 'Navigation and data',
        Component: NavigationAndData,
    },
];
