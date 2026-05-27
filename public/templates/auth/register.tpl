{extends file="layouts/main.tpl"}

{block name="title"}Register - Phlix{/block}

{block name="main"}
<div class="auth-page">
    <div class="auth-container">
        <div class="auth-header">
            <img src="/assets/images/logo.svg" alt="Phlix" height="48">
            {if $is_first_user}
                <h1>Welcome to Phlix</h1>
                <p>You're the first user — this account becomes the admin.</p>
            {else}
                <h1>Create an account</h1>
                <p>Get started with Phlix</p>
            {/if}
        </div>

        {if $error}
            <div class="auth-error" role="alert">{$error|escape:'html'}</div>
        {/if}

        <form class="auth-form" action="/auth/register" method="post">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autocomplete="username">
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required autocomplete="email">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="new-password">
            </div>

            <div class="form-group">
                <label for="password_confirm">Confirm Password</label>
                <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-primary btn-block">Create Account</button>
        </form>

        <div class="auth-footer">
            <p>Already have an account? <a href="/auth/login">Sign in</a></p>
        </div>
    </div>
</div>
{/block}