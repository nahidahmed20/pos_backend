<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign in | Aamar POS</title>
    <link rel="stylesheet" href="{{ asset('css/pos.css') }}">
</head>
<body class="login-page">
    <main class="login-layout">
        <section class="login-brand">
            <div class="brand-mark">A</div>
            <p class="eyebrow">AAMAR BUSINESS</p>
            <h1>Every branch.<br>One clear view.</h1>
            <p class="brand-copy">Manage sales, inventory and cash with one simple workspace.</p>
            <div class="brand-foot">Point of sale · Inventory · Branch management</div>
        </section>
        <section class="login-panel">
            <div class="login-card">
                <p class="eyebrow">WELCOME BACK</p>
                <h2>Sign in to your account</h2>
                <p class="muted">Use your assigned account to continue.</p>
                <form id="login-form">
                    <label for="email">Email address</label>
                    <input id="email" name="email" type="email" autocomplete="username" required autofocus>
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                    <p id="login-error" class="form-error" role="alert"></p>
                    <button class="button button-primary button-full" type="submit">Sign in</button>
                </form>
            </div>
            <p class="login-foot">Secure access for your team</p>
        </section>
    </main>
    <script>
        const loginForm = document.querySelector('#login-form');
        const loginError = document.querySelector('#login-error');
        loginForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            loginError.textContent = '';
            const submitButton = loginForm.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.textContent = 'Signing in…';
            try {
                const response = await fetch('/login', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        email: loginForm.email.value,
                        password: loginForm.password.value,
                    }),
                });
                if (!response.ok) {
                    const result = await response.json();
                    throw new Error(result.errors?.email?.[0] ?? 'Unable to sign in. Check your details and try again.');
                }
                window.location.assign(@json(route('pos.index')));
            } catch (error) {
                loginError.textContent = error.message;
                submitButton.disabled = false;
                submitButton.textContent = 'Sign in';
            }
        });
    </script>
</body>
</html>
