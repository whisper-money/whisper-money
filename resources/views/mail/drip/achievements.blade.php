{{-- What the sweep found, in one message. Prose and a short list, so the
     standard Markdown mail is the right shape: the designed artefact here is
     the medal itself, and it lives on the progress screen. --}}
<x-mail::message>
# {{ trans_choice('{1}You unlocked an achievement|[2,*]You unlocked :count achievements', count($lines), ['count' => count($lines)]) }}

{{ __('Hi :name,', ['name' => $userName]) }}

{{ trans_choice('{1}Your money crossed a line worth marking.|[2,*]Your money crossed a few lines worth marking.', count($lines)) }}

@foreach ($lines as $line)
- **{{ $line['name'] }}{{ $line['milestone'] ? ' '.$line['milestone'] : '' }}** — {{ $line['rarity'] }}
@endforeach

<x-mail::button :url="route('achievements.index')">
{{ __('See your progress') }}
</x-mail::button>

{{ __('Every milestone is dated when it actually happened, so the screen reads as your history rather than as today.') }}

Víctor

<x-slot:subcopy>
<a href="{{ $unsubscribeUrl }}">{{ __('Turn achievement emails off') }}</a>
</x-slot:subcopy>
</x-mail::message>
