<x-mail::message>
# OTP Code for the Super Admin panel

Use the following code to complete the login:

<x-mail::panel>
<strong style="font-size: 1.5rem;">{{ $code }}</strong>
</x-mail::panel>

The code expires at {{ $expiresAt }}.

If you did not request this login, ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
