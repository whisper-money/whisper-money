import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { LockKeyhole, LockKeyholeOpen } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from './ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from './ui/dialog';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from './ui/tooltip';
import UnlockMessageDialog from './unlock-message-dialog';

export function EncryptionKeyButton() {
    const {
        isKeySet,
        encryptedMessageData,
        fetchEncryptedMessage,
        clearEncryptionKey,
    } = useEncryptionKey();
    const [showUnlockDialog, setShowUnlockDialog] = useState(false);
    const [showClearDialog, setShowClearDialog] = useState(false);

    useEffect(() => {
        if (!encryptedMessageData) {
            fetchEncryptedMessage();
        }
    }, []);

    function handleClick() {
        if (isKeySet) {
            setShowClearDialog(true);
        } else {
            if (!encryptedMessageData) {
                fetchEncryptedMessage();
            }
            setShowUnlockDialog(true);
        }
    }

    function handleClearKey() {
        clearEncryptionKey();
        setShowClearDialog(false);
    }

    function handleUnlock() {
        setShowUnlockDialog(false);
    }

    return (
        <>
            <TooltipProvider>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="h-9 w-9"
                            onClick={handleClick}
                            aria-label={
                                isKeySet
                                    ? 'Lock encryption key'
                                    : 'Unlock encryption key'
                            }
                        >
                            {isKeySet ? (
                                <LockKeyholeOpen className="h-5 w-5" />
                            ) : (
                                <LockKeyhole className="h-5 w-5" />
                            )}
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>
                        {isKeySet
                            ? 'Click to lock encryption key'
                            : 'Click to unlock encryption key'}
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>

            {encryptedMessageData && (
                <UnlockMessageDialog
                    open={showUnlockDialog}
                    onOpenChange={setShowUnlockDialog}
                    onUnlock={handleUnlock}
                    encryptedContent={encryptedMessageData.encrypted_content}
                    iv={encryptedMessageData.iv}
                    salt={encryptedMessageData.salt}
                />
            )}

            <Dialog open={showClearDialog} onOpenChange={setShowClearDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Clear Encryption Key?</DialogTitle>
                        <DialogDescription>
                            This will remove your encryption key from this
                            browser session. You'll need to enter your password
                            again to unlock encrypted content.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setShowClearDialog(false)}
                        >
                            Cancel
                        </Button>
                        <Button onClick={handleClearKey}>Clear Key</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
