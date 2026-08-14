<x-mail::message>
# Password reset

Hi {{ $user->name }},

we received a password reset request for your account. Click the button below to choose a new password:

<x-mail::button :url="$resetUrl">
Reset password
</x-mail::button>

If you did not request this reset you can ignore this email: the link will automatically stop working.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
