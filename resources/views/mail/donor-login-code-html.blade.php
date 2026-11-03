@include('mail.auth-code-html', [
    'title' => 'Your LifeFlow verification code',
    'heading' => 'Your verification code',
    'instructions' => 'Enter this six-digit code in LifeFlow to continue signing in.',
    'minutes' => 10,
    'ignoreNotice' => "If you didn't request this, you can ignore this email.",
])
