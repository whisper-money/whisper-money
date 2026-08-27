import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { FileSpreadsheet, Upload, X } from 'lucide-react';
import { useCallback, useState } from 'react';

/**
 * Everything SheetJS can read for us. Apple Numbers files are a zipped IWA
 * bundle, but the same reader handles them, so they need no special casing
 * beyond being let through here.
 */
const supportedExtensions = ['.csv', '.xls', '.xlsx', '.numbers'];

export function isSupportedImportFile(file: File | null | undefined): boolean {
    if (!file || !file.name) {
        return false;
    }

    const lastDotIndex = file.name.lastIndexOf('.');
    if (lastDotIndex === -1) {
        return false;
    }

    return supportedExtensions.includes(
        file.name.toLowerCase().slice(lastDotIndex),
    );
}

interface ImportStepUploadProps {
    file: File | null;
    onFileSelect: (file: File) => void;
    onNext: () => void;
    onBack: () => void;
    showBackButton?: boolean;
}

export function ImportStepUpload({
    file,
    onFileSelect,
    onNext,
    onBack,
    showBackButton = true,
}: ImportStepUploadProps) {
    const [isDragging, setIsDragging] = useState(false);

    const handleDragOver = useCallback((e: React.DragEvent) => {
        e.preventDefault();
        setIsDragging(true);
    }, []);

    const handleDragLeave = useCallback((e: React.DragEvent) => {
        e.preventDefault();
        setIsDragging(false);
    }, []);

    const handleDrop = useCallback(
        (e: React.DragEvent) => {
            e.preventDefault();
            setIsDragging(false);

            const droppedFile = e.dataTransfer.files[0];
            if (droppedFile && isSupportedImportFile(droppedFile)) {
                onFileSelect(droppedFile);
            }
        },
        [onFileSelect],
    );

    const handleFileInput = useCallback(
        (e: React.ChangeEvent<HTMLInputElement>) => {
            const selectedFile = e.target.files?.[0];
            if (selectedFile && isSupportedImportFile(selectedFile)) {
                onFileSelect(selectedFile);
            }
        },
        [onFileSelect],
    );

    const formatFileSize = (bytes: number): string => {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    };

    return (
        <div className="flex flex-col gap-6">
            <div
                className={cn(
                    'flex min-h-[200px] flex-col items-center justify-center rounded-lg border-2 border-dashed p-8 transition-colors',
                    isDragging && 'border-primary bg-accent',
                    !isDragging && 'border-border hover:border-primary/50',
                )}
                onDragOver={handleDragOver}
                onDragLeave={handleDragLeave}
                onDrop={handleDrop}
            >
                {!file ? (
                    <>
                        <Upload className="mb-4 h-12 w-12 text-muted-foreground" />
                        <p className="mb-2 text-sm font-medium">
                            {__('Drop your file here, or click to browse')}
                        </p>
                        <p className="mb-4 text-xs text-muted-foreground">
                            {__('Supports CSV, XLS, XLSX, and Numbers files')}
                        </p>
                        <Button asChild variant="secondary">
                            <label className="cursor-pointer">
                                {__('Browse Files')}

                                <input
                                    type="file"
                                    className="hidden"
                                    accept={supportedExtensions.join(',')}
                                    onChange={handleFileInput}
                                />
                            </label>
                        </Button>
                    </>
                ) : (
                    <div className="flex w-full items-center gap-4 rounded-lg border bg-card p-4">
                        <FileSpreadsheet className="h-8 w-8 text-primary" />
                        <div className="flex-1">
                            <p className="font-medium">{file.name}</p>
                            <p className="text-xs text-muted-foreground">
                                {formatFileSize(file.size)}
                            </p>
                        </div>
                        <Button
                            variant="ghost"
                            size="icon"
                            onClick={(e) => {
                                e.preventDefault();
                                const input = document.querySelector(
                                    'input[type="file"]',
                                ) as HTMLInputElement;
                                if (input) input.value = '';
                                onFileSelect(undefined as unknown as File);
                            }}
                        >
                            <X className="h-4 w-4" />
                        </Button>
                    </div>
                )}
            </div>

            <div
                className={cn(
                    'flex',
                    showBackButton ? 'justify-between' : 'justify-end',
                )}
            >
                {showBackButton && (
                    <Button variant="outline" onClick={onBack}>
                        {__('Back')}
                    </Button>
                )}
                <Button onClick={onNext} disabled={!file}>
                    {__('Next')}
                </Button>
            </div>
        </div>
    );
}
