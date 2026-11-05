{{-- The offer both Black Friday 2026 emails carry: the prices before and after
     the code, and the code itself. One place, so the two sends cannot quote
     different numbers. The prices are the BLACKFRIDAY40 promotion code applied
     to the default tier (40% off, repeating for 12 months). --}}
<x-mail::table>
| {{ __('Plan') }}        | {{ __('Usual price') }}  | {{ __('Your first year') }}                          |
| :---------------------- | :----------------------- | :--------------------------------------------------- |
| **{{ __('Yearly') }}**  | {{ __('€53.94 a year') }} | **{{ __('€32.36 a year') }}** ({{ __('€2.70 a month') }}) |
| **{{ __('Monthly') }}** | {{ __('€8.99 a month') }} | **{{ __('€5.39 a month for 12 months') }}**          |
</x-mail::table>

<x-mail::panel>
{{ __('Your code: :code', ['code' => '**BLACKFRIDAY40**']) }}

{{ __('On the payment page, tap "Add promotion code" and type it in.') }}

{{ __('The code is for a first paid subscription: if you have paid for Whisper Money before, it will not apply.') }}
</x-mail::panel>

{{ __('After the first year it renews at the usual price, €53.94 a year or €8.99 a month. You can cancel whenever you like before then.') }}
