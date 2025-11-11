import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { FileSpreadsheet, Upload, X } from 'lucide-react';
import { useCallback, useState } from 'react';

interface ImportStepUploadProps {
    file: File | null;
    onFileSelect: (file: File) => void;
    onNext: () => void;
    onBack: () => void;
}

export function ImportStepUpload({
    file,
    onFileSelect,
    onNext,
    onBack,
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

    const isValidFile = useCallback(
        (file: File | null | undefined): boolean => {
            if (!file || !file.name) {
                return false;
            }
            const validExtensions = ['.csv', '.xls', '.xlsx'];
            const lastDotIndex = file.name.lastIndexOf('.');
            if (lastDotIndex === -1) {
                return false;
            }
            const extension = file.name.toLowerCase().slice(lastDotIndex);
            return validExtensions.includes(extension);
        },
        [],
    );

    const handleDrop = useCallback(
        (e: React.DragEvent) => {
            e.preventDefault();
            setIsDragging(false);

            const droppedFile = e.dataTransfer.files[0];
            if (droppedFile && isValidFile(droppedFile)) {
                onFileSelect(droppedFile);
            }
        },
        [onFileSelect, isValidFile],
    );

    const handleFileInput = useCallback(
        (e: React.ChangeEvent<HTMLInputElement>) => {
            const selectedFile = e.target.files?.[0];
            if (selectedFile && isValidFile(selectedFile)) {
                onFileSelect(selectedFile);
            }
        },
        [onFileSelect, isValidFile],
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
                            Drop your file here, or click to browse
                        </p>
                        <p className="mb-4 text-xs text-muted-foreground">
                            Supports CSV, XLS, and XLSX files
                        </p>
                        <Button asChild variant="secondary">
                            <label className="cursor-pointer">
                                Browse Files
                                <input
                                    type="file"
                                    className="hidden"
                                    accept=".csv,.xls,.xlsx"
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

            <div className="flex justify-between">
                <Button variant="outline" onClick={onBack}>
                    Back
                </Button>
                <Button onClick={onNext} disabled={!file}>
                    Next
                </Button>
            </div>
        </div>
    );
}
