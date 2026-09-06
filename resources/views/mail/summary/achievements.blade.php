{{-- The medals: what the month earned, and what is within reach now.

     Sits between the figures and the to-dos on purpose. The unlocked ones close
     the month off — they are the reward for what the rows above just described —
     and the next ones open the following one, which is what the to-dos are
     already about.

     Same rule as everywhere else in this report: no block at all when there is
     nothing to say. --}}
@foreach ($achievements as $group)
    <p style="margin:34px 0 8px;font-size:15px;font-weight:700;color:#18181b;letter-spacing:-0.01em;">{{ $group['title'] }}</p>

    @foreach ($group['lines'] as $line)
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-top:1px solid #e4e4e7;">
            <tr><td style="padding:15px 0;font-size:15px;line-height:1.5;color:#52525b;">{!! $line !!}</td></tr>
        </table>
    @endforeach
@endforeach

<p style="margin:15px 0 0;"><a href="{{ $achievementsUrl }}" style="font-size:13px;font-weight:600;color:#18181b;text-decoration:none;border-bottom:1px solid #d4d4d8;">{{ __('See all your medals') }}</a></p>
