{{-- The analysis block, in one of three states.

     A Pro reader with AI consent gets the real thing.

     When the model could not be reached they get a plain line saying so. The
     locked block below would be a lie to them: it sells the plan they already
     pay for and its button points at a setting that is already on. That is the
     state a provider outage puts every consenting reader in at once - the whole
     batch on the 3rd - so it cannot borrow the paywall's copy.

     Everyone else gets the locked block: the free reader because it is what Pro
     buys, and the Pro reader without consent because nothing about them may
     reach a model until they say so. The only thing that differs between those
     two is the button. --}}
@if ($analysis !== null)
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:24px;"><tr>
        <td width="4" style="background:#18181b;font-size:0;line-height:0;">&nbsp;</td>
        <td style="background:#fafafa;padding:18px 20px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                <td style="font-size:10px;font-weight:600;letter-spacing:0.09em;text-transform:uppercase;color:#a1a1aa;padding-right:9px;">{{ __('Why this happened') }}</td>
                <td><span style="display:inline-block;font-size:9px;font-weight:600;letter-spacing:0.1em;text-transform:uppercase;background:#18181b;color:#ffffff;padding:3px 6px;border-radius:3px;">{{ __('Pro') }}</span></td>
            </tr></table>

            @foreach (preg_split('/\n{2,}/', trim($analysis)) as $paragraph)
                <p style="margin:12px 0 0;font-size:14px;line-height:1.55;color:#52525b;">{{ trim($paragraph) }}</p>
            @endforeach

            <p style="margin:13px 0 0;padding-top:11px;border-top:1px solid #e4e4e7;font-size:11px;line-height:1.5;color:#a1a1aa;">{{ __('Written by a model from your month\'s totals and the names of your accounts, never from your individual transactions. You can turn it off in Settings → AI.') }}</p>
        </td>
    </tr></table>
@elseif ($pro)
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:24px;"><tr>
        <td width="4" style="background:#d4d4d8;font-size:0;line-height:0;">&nbsp;</td>
        <td style="background:#fafafa;padding:18px 20px;">
            <p style="margin:0;font-size:10px;font-weight:600;letter-spacing:0.09em;text-transform:uppercase;color:#a1a1aa;">{{ __('Why this happened') }}</p>
            <p style="margin:12px 0 0;font-size:14px;line-height:1.55;color:#52525b;">{{ __('We could not write your analysis this month. Every figure below is unaffected.') }}</p>
        </td>
    </tr></table>
@else
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:24px;"><tr>
        <td width="4" style="background:#d4d4d8;font-size:0;line-height:0;">&nbsp;</td>
        <td style="background:#fafafa;padding:18px 20px;">
            <p style="margin:0;font-size:10px;font-weight:600;letter-spacing:0.09em;text-transform:uppercase;color:#a1a1aa;">{{ __('Why this happened') }}</p>
            <p style="margin:12px 0 0;font-size:14px;line-height:1.55;color:#52525b;">{{ $lockedPitch }}</p>

            {{-- Grey bars rather than blurred text: CSS filters are unreliable in
                 email, and a bar reads unmistakably as "there is more here". --}}
            <div style="margin:15px 0 16px;">
                <div style="height:9px;border-radius:2px;background:#e4e4e7;width:100%;font-size:0;line-height:0;">&nbsp;</div>
                <div style="height:9px;border-radius:2px;background:#e4e4e7;width:94%;margin-top:8px;font-size:0;line-height:0;">&nbsp;</div>
                <div style="height:9px;border-radius:2px;background:#e4e4e7;width:61%;margin-top:8px;font-size:0;line-height:0;">&nbsp;</div>
            </div>

            <a href="{{ $lockedUrl }}" style="display:inline-block;background:#18181b;color:#ffffff;font-size:13px;font-weight:600;padding:10px 16px;border-radius:4px;text-decoration:none;">{{ $lockedAction }}</a>
        </td>
    </tr></table>
@endif
