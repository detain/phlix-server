{extends file="layouts/main.tpl"}

{block name="title"}Login - Phlix{/block}

{block name="main"}
<div class="auth-page">
    <div class="auth-container">
        <div class="auth-header">
            <img src="/assets/images/logo.svg" alt="Phlix" height="48">
            <h1>Welcome back</h1>
            <p>Sign in to your Phlix account</p>
        </div>

        <form class="auth-form" action="/auth/login" method="post">
            <div class="form-group">
                <label for="username">Username or Email</label>
                <input type="text" id="username" name="username" required autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>

            <div class="form-group form-check">
                <input type="checkbox" id="remember" name="remember">
                <label for="remember">Remember me</label>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Sign In</button>
        </form>

        <div class="auth-footer">
            <p>Don't have an account? <a href="/auth/register">Sign up</a></p>
        </div>
    </div>
</div>
{/block}