<x-mail::message>
@switch ($kind)
@case ('verify')
# Verify your email

Confirm this email address to finish creating your {{ $brand }} account.

<x-mail::button :url="$url">
Verify my email
</x-mail::button>

This link works for 24 hours. If you didn’t create an account, you can ignore this email: nothing happens until the link is opened.
@break
@case ('reset')
# Choose a new password

Hi {{ $name }}, use the button to choose a new password for your {{ $brand }} account.

<x-mail::button :url="$url">
Choose a new password
</x-mail::button>

This link works for 60 minutes. If you didn’t ask for it, ignore this email: your password stays as it is.
@break
@default
# You already have an account

Hi {{ $name }}, someone (hopefully you) tried to create a {{ $brand }} account with this email address, but it already has one.

@if ($usesPassword)
Sign in with your email and password. If you forgot the password, use **Forgot password?** on the sign-in page.
@else
Sign in with the same Google account you used before.
@endif

<x-mail::button :url="$signInUrl">
Sign in
</x-mail::button>

If this wasn’t you, you can ignore this email.
@endswitch

{{ $brand }}
</x-mail::message>
