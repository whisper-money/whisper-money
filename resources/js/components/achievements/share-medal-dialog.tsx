import { card } from '@/actions/App/Http/Controllers/AchievementController';
import { ShareCardDialog } from '@/components/share/share-card-dialog';
import { Checkbox } from '@/components/ui/checkbox';
import { useLocale } from '@/hooks/use-locale';
import { type AchievementMedal } from '@/types';
import { __ } from '@/utils/i18n';
import { useState } from 'react';

/**
 * Posting one medal.
 *
 * Everything about shapes, skins, previews and the share sheet lives in
 * {@see ShareCardDialog}, which the monthly summary shares. The one thing that
 * is only true of a medal is here: a money medal writes its amount by default
 * and the reader can take it off. Off, the medal still says what it is — the
 * name and the tier carry it — and the picture stops being a statement about
 * how much money somebody has.
 *
 * The language rides in the URL even though the server reads it off the request
 * either way. The picture is drawn in the reader's language and cached under it,
 * but the URL without it is the same URL in every language — so after switching
 * language the browser answers from its own cache and hands back the old one for
 * as long as the response is fresh.
 */

/** The medal's own figure is an amount, so hiding it is a choice worth having. */
function hasAmount(medal: AchievementMedal): boolean {
    return medal.figure?.type === 'money';
}

export function ShareMedalDialog({
    medal,
    children,
}: {
    medal: AchievementMedal;
    children: React.ReactNode;
}) {
    const [amount, setAmount] = useState(true);
    const locale = useLocale();

    return (
        <ShareCardDialog
            title={__('Share this medal')}
            description={__(
                'Pick a shape and a skin. Nothing leaves your device until you send it.',
            )}
            subject={medal.name ?? __('Achievement')}
            url={({ format, theme, preview }) =>
                `${card({ medal: medal.key, format, theme }).url}?${new URLSearchParams(
                    {
                        ...(preview ? { preview: '1' } : {}),
                        amount: amount ? '1' : '0',
                        lang: locale,
                    },
                ).toString()}`
            }
            filename={({ format, theme }) =>
                `whisper-money-${medal.key.replace('.', '-')}-${format}-${theme}.png`
            }
            controls={
                hasAmount(medal) && (
                    <label className="flex cursor-pointer items-start gap-2.5 rounded-lg border p-3">
                        <Checkbox
                            checked={!amount}
                            onCheckedChange={(checked) =>
                                setAmount(checked !== true)
                            }
                            className="mt-0.5"
                        />
                        <span className="flex flex-col gap-0.5">
                            <span className="text-sm font-medium">
                                {__('Leave the amount off')}
                            </span>
                            <span className="text-xs text-pretty text-muted-foreground">
                                {__(
                                    'The medal, its name and its tier still show. The figure does not.',
                                )}
                            </span>
                        </span>
                    </label>
                )
            }
        >
            {children}
        </ShareCardDialog>
    );
}
