<x-mail::message>
# {{ __(':bank is having a temporary problem on their side', ['bank' => $bankName]) }}

{{ __('Hi :name,', ['name' => $userName]) }}

{{ __('We noticed that :bank has stopped answering our requests for your transactions and balances. The failure is on the bank side, not in Whisper Money, and it affects everyone connected to :bank right now.', ['bank' => $bankName]) }}

{{ __('You do not need to do anything. Your connection and your consent are still valid, so there is no need to reconnect :bank — that would not help until the bank answers again.', ['bank' => $bankName]) }}

{{ __('All your data is safe. Nothing has been lost, and everything already in Whisper Money stays exactly as it is. Once :bank responds again, the missing transactions and balances fill in automatically.', ['bank' => $bankName]) }}

{{ __('You may see the connection flagged with an error in Whisper Money. That is this same problem, and retrying it will not help until :bank is back.', ['bank' => $bankName]) }}

{{ __('We are already on it, working with our banking provider and with the bank to get it fixed as soon as possible. We will let you know if we need anything from you.') }}

{{ __('Best,') }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
