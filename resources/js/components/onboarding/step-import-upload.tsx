import { StepButton } from '@/components/onboarding/step-button';
import {
    StepChevron,
    StepList,
    StepNumber,
    StepRow,
} from '@/components/onboarding/step-list';
import {
    StepCallout,
    StepEmphasis,
    StepScreen,
} from '@/components/onboarding/step-screen';
import { SUPPORTED_IMPORT_EXTENSIONS } from '@/lib/transaction-import';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { CircleQuestionMark, Upload } from 'lucide-react';
import { useRef, useState } from 'react';

/** What a bank export looks like on the other side, for someone who has never done it. */
const EXPORT_STEPS = [
    "Log in to your bank's website or app",
    "Open the account's movement history",
    'Look for "Export" or "Download"',
    'Ask for CSV or Excel, not PDF',
];

interface StepImportUploadProps {
    onFileSelect: (file: File) => void;
    /** The way past a step someone has no file for. */
    onSkip: () => void;
}

export function StepImportUpload({
    onFileSelect,
    onSkip,
}: StepImportUploadProps) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [isDragging, setIsDragging] = useState(false);
    const [showExportSteps, setShowExportSteps] = useState(false);

    const handleDrop = (event: React.DragEvent) => {
        event.preventDefault();
        setIsDragging(false);

        const file = event.dataTransfer.files[0];

        if (file) {
            onFileSelect(file);
        }
    };

    const handleInput = (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];

        // Cleared so picking the same file again after a failure still fires.
        event.target.value = '';

        if (file) {
            onFileSelect(file);
        }
    };

    return (
        <StepScreen
            title={__('Bring in your history')}
            description={__(
                "Whatever your bank or your old app gives you. We'll work out which column is which — you only check it.",
            )}
            footer={
                <>
                    <StepButton
                        text={__('Choose a file')}
                        onClick={() => inputRef.current?.click()}
                    />
                    <StepButton
                        text={__("I don't have one yet")}
                        variant="ghost"
                        onClick={onSkip}
                    />
                </>
            }
        >
            <div className="flex flex-col gap-6">
                <label
                    onDragOver={(event) => {
                        event.preventDefault();
                        setIsDragging(true);
                    }}
                    onDragLeave={() => setIsDragging(false)}
                    onDrop={handleDrop}
                    className={cn(
                        'flex cursor-pointer flex-col items-center justify-center gap-3 rounded-lg border border-dashed px-6 py-10 text-center transition-colors',
                        isDragging && 'border-primary bg-accent',
                    )}
                >
                    <Upload className="size-6 text-muted-foreground" />
                    <div className="flex flex-col gap-1">
                        <span className="text-base leading-tight font-medium">
                            {__('Drop the file here')}
                        </span>
                        <span className="text-sm text-muted-foreground">
                            {__('CSV, Excel or Numbers · up to 10 MB')}
                        </span>
                    </div>
                    <input
                        ref={inputRef}
                        type="file"
                        className="hidden"
                        accept={SUPPORTED_IMPORT_EXTENSIONS.join(',')}
                        onChange={handleInput}
                    />
                </label>

                <StepList>
                    <StepRow
                        icon={CircleQuestionMark}
                        title={__('How do I get this out of my bank?')}
                        description={__('Four steps, takes a minute')}
                        trailing={showExportSteps ? undefined : <StepChevron />}
                        onClick={
                            showExportSteps
                                ? undefined
                                : () => setShowExportSteps(true)
                        }
                    />
                    {showExportSteps &&
                        EXPORT_STEPS.map((step, index) => (
                            <StepRow
                                key={step}
                                size="compact"
                                leading={<StepNumber>{index + 1}</StepNumber>}
                                title={__(step)}
                            />
                        ))}
                </StepList>

                <StepCallout>
                    <StepEmphasis
                        sentence={__(
                            'Your file is read :browser. We check which movements you already have, and nothing is saved until you have seen the preview and said yes.',
                        )}
                        word={__('in your browser')}
                    />
                </StepCallout>
            </div>
        </StepScreen>
    );
}
