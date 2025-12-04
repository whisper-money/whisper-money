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

    const handleRemoveLogo = useCallback(() => {
        if (value.logoPreview) {
            URL.revokeObjectURL(value.logoPreview);
        }
        onChange({ ...value, logo: null, logoPreview: null });
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    }, [onChange, value]);

    const handleNameChange = useCallback(
        (e: React.ChangeEvent<HTMLInputElement>) => {
            onChange({ ...value, name: e.target.value });
        },
        [onChange, value],
    );

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <span className="text-sm font-medium">Create custom bank</span>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onCancel}
                >
                    Cancel
                </Button>
            </div>

            <div className="space-y-2">
                <Label htmlFor="bank_name">Bank name</Label>
                <Input
                    id="bank_name"
                    placeholder="Bank name"
                    defaultValue={defaultName}
                    value={value.name}
                    onChange={handleNameChange}
                    required
                />
            </div>

            <div className="space-y-2">
                <Label htmlFor="bank_logo">Logo (optional)</Label>
                <div className="flex items-center gap-4">
                    {value.logoPreview ? (
                        <div className="relative">
                            <img
                                src={value.logoPreview}
                                alt="Bank logo preview"
                                className="h-16 w-16 rounded border object-contain"
                            />
                            <Button
                                type="button"
                                variant="destructive"
                                size="icon"
                                className="absolute -top-2 -right-2 h-5 w-5"
                                onClick={handleRemoveLogo}
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </div>
                    ) : (
                        <button
                            type="button"
                            onClick={() => fileInputRef.current?.click()}
                            className="flex h-16 w-16 items-center justify-center rounded border-2 border-dashed border-muted-foreground/25 transition-colors hover:border-muted-foreground/50"
                        >
                            <ImagePlus className="h-6 w-6 text-muted-foreground" />
                        </button>
                    )}
                    <input
                        ref={fileInputRef}
                        type="file"
                        id="bank_logo"
                        accept="image/png,image/jpeg,image/webp"
                        className="hidden"
                        onChange={handleFileChange}
                    />
                    <div className="text-xs text-muted-foreground">
                        <p>
                            Max {MAX_DIMENSIONS}x{MAX_DIMENSIONS}px
                        </p>
                        <p>Square images only</p>
                        <p>Max {MAX_FILE_SIZE / 1024}KB</p>
                    </div>
                </div>
                {error && <p className="text-sm text-destructive">{error}</p>}
            </div>
        </div>
    );
}
