import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import {
    StepCallout,
    StepError,
    StepScreen,
} from '@/components/onboarding/step-screen';
import { __ } from '@/utils/i18n';
import { FileSpreadsheet, Search } from 'lucide-react';

interface StepImportFailedProps {
    fileName: string;
    /** Why this file could not be read, in the parser's own words. */
    reason: string;
    onChooseAnother: () => void;
    /** Absent on a plan with no bank connections to offer. */
    onConnectBank?: () => void;
}

/**
 * The file opened and had nothing we could use in it — almost always a PDF
 * statement, which is the thing every bank offers first.
 *
 * The screen spends itself on the way out rather than on the error: someone
 * whose bank only prints PDFs cannot fix the file, and connecting the bank is
 * both less work and the only thing that will actually work for them.
 */
export function StepImportFailed({
    fileName,
    reason,
    onChooseAnother,
    onConnectBank,
}: StepImportFailedProps) {
    return (
        <StepScreen
            title={__("We can't read that one")}
            description={__(
                'It opened, but there was nothing that looks like a list of movements inside.',
            )}
            footer={
                <>
                    <StepButton
                        text={__('Choose another file')}
                        onClick={onChooseAnother}
                    />
                    {onConnectBank && (
                        <StepButton
                            text={__('Connect the bank instead')}
                            variant="ghost"
                            onClick={onConnectBank}
                        />
                    )}
                </>
            }
        >
            <div className="flex flex-col gap-6">
                <StepError>
                    {fileName} — {reason}
                </StepError>

                <StepList>
                    <StepRow
                        icon={FileSpreadsheet}
                        title={__('CSV, Excel or Numbers')}
                        description={__(
                            "A PDF statement can't be read reliably enough to trust it",
                        )}
                    />
                    <StepRow
                        icon={Search}
                        title={__('Most banks offer both')}
                        description={__(
                            'Look for "Export" rather than "Download statement"',
                        )}
                    />
                </StepList>

                {onConnectBank && (
                    <StepCallout>
                        {__(
                            'If your bank only gives PDFs, connect it instead — open banking gives us the movements directly, and it is less work than fighting a file.',
                        )}
                    </StepCallout>
                )}
            </div>
        </StepScreen>
    );
}
