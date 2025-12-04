import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ImagePlus, X } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';

const MAX_FILE_SIZE = 500 * 1024; // 500KB
const MAX_DIMENSIONS = 500;

export interface CustomBankData {
    name: string;
    logo: File | null;
    logoPreview: string | null;
}

interface CustomBankFormProps {
    defaultName?: string;
    onCancel: () => void;
    onChange: (data: CustomBankData) => void;
    value: CustomBankData;
}

export function CustomBankForm({
    defaultName = '',
    onCancel,
    onChange,
    value,
}: CustomBankFormProps) {
    const [error, setError] = useState<string | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const validateImage = useCallback((file: File): Promise<string | null> => {
        return new Promise((resolve) => {
            if (file.size > MAX_FILE_SIZE) {
                resolve(
                    `File size must be less than ${MAX_FILE_SIZE / 1024}KB`,
                );
                return;
            }

            const img = new Image();
            const objectUrl = URL.createObjectURL(file);

            img.onload = () => {
                URL.revokeObjectURL(objectUrl);

                if (img.width !== img.height) {
                    resolve('Image must be square (same width and height)');
                    return;
                }

                if (img.width > MAX_DIMENSIONS) {
                    resolve(
                        `Image dimensions must be ${MAX_DIMENSIONS}x${MAX_DIMENSIONS} pixels or smaller`,
                    );
                    return;
                }

                resolve(null);
            };

            img.onerror = () => {
                URL.revokeObjectURL(objectUrl);
                resolve('Invalid image file');
            };

            img.src = objectUrl;
        });
    }, []);

    const handleFileChange = useCallback(
        async (e: React.ChangeEvent<HTMLInputElement>) => {
            const file = e.target.files?.[0];
            setError(null);

            if (!file) {
                onChange({ ...value, logo: null, logoPreview: null });
                return;
            }

            const validationError = await validateImage(file);
            if (validationError) {
                setError(validationError);
                e.target.value = '';
                return;
            }

            const preview = URL.createObjectURL(file);
            onChange({ ...value, logo: file, logoPreview: preview });
        },
        [onChange, validateImage, value],
    );

    const handleNameChange = useCallback(
        (e: React.ChangeEvent<HTMLInputElement>) => {
            onChange({ ...value, name: e.target.value });
        },
        [onChange, value],
    );

    return (
        <>
            <Button
                type="button"
                variant="link"
                size="sm"
                onClick={onCancel}
                className="float-right -mr-2 ml-auto cursor-pointer"
            >
                <X className="size-4" />
            </Button>

            <div className="mt-1 space-y-2 rounded-sm border border-border p-3">
                <div className="space-y-2">
                    <Label htmlFor="bank_name">Bank name</Label>
                    <Input
                        id="bank_name"
                        placeholder="Bank name"
                        defaultValue={defaultName}
                        value={value.name}
                        className="mt-1"
                        onChange={handleNameChange}
                        required
                    />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="bank_logo">Logo</Label>
                    <div className="mt-1 flex items-center gap-2">
                        <Input
                            ref={fileInputRef}
                            type="file"
                            id="bank_logo"
                            accept="image/png,image/jpeg,image/webp"
                            onChange={handleFileChange}
                        />
                        <button
                            type="button"
                            onClick={() => fileInputRef.current?.click()}
                            className="flex aspect-square h-9 w-9 items-center justify-center rounded-lg border border-muted-foreground/25 transition-colors hover:border-muted-foreground/50"
                        >
                            {value.logoPreview ? (
                                <img
                                    src={value.logoPreview}
                                    alt="Bank logo preview"
                                    className="size-5 object-contain"
                                />
                            ) : (
                                <ImagePlus className="size-4 text-muted-foreground" />
                            )}
                        </button>
                    </div>
                    <div className="text-xs text-muted-foreground">
                        <p>
                            Square images only (max {MAX_DIMENSIONS}x
                            {MAX_DIMENSIONS}px) / {MAX_FILE_SIZE / 1024}KB max.
                        </p>
                    </div>
                    {error && (
                        <p className="text-sm text-destructive">{error}</p>
                    )}
                </div>
            </div>
        </>
    );
}
