<x-mail::message>
# Monthly user emails export

The attached CSV lists the email address of every active (non-deleted) user.

**Users:** {{ $userCount }}

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
