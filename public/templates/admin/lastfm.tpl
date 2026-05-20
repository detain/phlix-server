{extends file="admin/layout.tpl"}

{block name="title"}Connect Last.fm - Admin{/block}

{block name="main"}
<section class="lastfm-connect-page" data-api-base="/api/v1/admin/lastfm">
    <header class="page-header">
        <h1>Last.fm Scrobbling</h1>
        <p class="page-subtitle">
            Connect your Last.fm account so Phlix can submit "Now Playing" updates
            and scrobbles whenever you finish listening to a track.
        </p>
    </header>

    {if !$configured}
    <div class="alert alert-warning">
        <strong>Last.fm is not configured.</strong>
        Set <code>LASTFM_API_KEY</code> and <code>LASTFM_SHARED_SECRET</code>
        in the environment, then restart the server.
    </div>
    {else}

    <section class="lastfm-status">
        {if $session}
        <div class="card">
            <h2>Connected</h2>
            <p>You are scrobbling as <strong>{$session.username|escape}</strong>.</p>
            <p>Connected at <code>{$session.connected_at|escape}</code>.</p>
            <form method="post" action="/admin/lastfm/disconnect">
                <button type="submit" class="btn btn-danger">Disconnect Last.fm</button>
            </form>
        </div>
        {else}
        <div class="card">
            <h2>Not yet connected</h2>
            <p>Click the button below to authorise Phlix with Last.fm. You will
               be redirected to last.fm/api/auth, sign in, approve access, and
               sent back here to finish setup.</p>
            <a href="{$auth_url|escape}" class="btn btn-primary">Connect Last.fm</a>
            <p class="muted">
                Callback URL: <code>{$callback_url|escape}</code>
            </p>
        </div>
        {/if}
    </section>

    <section class="lastfm-rules">
        <h2>Scrobble rules</h2>
        <p>Phlix follows Last.fm's official scrobble rules: a scrobble is
           submitted only when both conditions are satisfied at the moment
           playback stops:</p>
        <ul>
            <li>the track is longer than 30 seconds, <em>and</em></li>
            <li>the user has listened to more than 50% of it.</li>
        </ul>
        <p>"Now Playing" updates are submitted on every playback start,
           regardless of those rules.</p>
    </section>

    {/if}
</section>
{/block}
