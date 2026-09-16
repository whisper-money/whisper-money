{{-- The footer line on a marketing email. Goes inside the message's subcopy
     slot, which has to stay in the view itself for Blade to register it:

     <x-slot:subcopy>@include('mail.partials.marketing-unsubscribe')</x-slot:subcopy>

     Guarded, because a view copied from a campaign into a notice
     (`marketing: false`) gets no URL, and a dead unsubscribe link on a message
     nobody can unsubscribe from is worse than no link at all. --}}
@isset($unsubscribeUrl)
<a href="{{ $unsubscribeUrl }}">{{ __('Stop receiving news and offers') }}</a>
@endisset
