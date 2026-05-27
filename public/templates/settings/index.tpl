{extends file="layouts/main.tpl"}

{block name="title"}Settings - Phlix{/block}

{block name="main"}
<div class="settings-page">
    <header class="settings-header">
        <h1>Settings</h1>
        <p>Manage your Phlix account, playback preferences, and parental controls.</p>
    </header>

    <section class="settings-section">
        <h2>Account</h2>
        <p>Signed in as <strong>{$user.display_name|default:'User'|escape:'html'}</strong>.</p>
        <p><a href="/login" class="btn btn-secondary">Sign in / Switch profile</a></p>
    </section>

    <section class="settings-section">
        <h2>Playback</h2>
        <form id="playback-settings" class="settings-form">
            <label>
                Default quality
                <select name="quality">
                    <option value="auto">Auto</option>
                    <option value="1080p">1080p</option>
                    <option value="720p">720p</option>
                    <option value="480p">480p</option>
                </select>
            </label>
            <label>
                <input type="checkbox" name="autoplay" checked>
                Autoplay next episode
            </label>
            <button type="submit" class="btn btn-primary">Save</button>
        </form>
    </section>

    <section class="settings-section">
        <h2>Parental controls</h2>
        <p>PIN-protect mature content per profile. <a href="/admin/dashboard">Manage profiles &rarr;</a></p>
    </section>

    <section class="settings-section">
        <h2>Plugins</h2>
        <p>Install or configure plugins. <a href="/admin/plugins">Plugin manager &rarr;</a></p>
    </section>
</div>
{literal}
<script>
(function () {
    const form = document.getElementById('playback-settings');
    if (!form) return;
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(form);
        const payload = {
            quality: formData.get('quality'),
            autoplay: formData.get('autoplay') === 'on',
        };
        try {
            const res = await fetch('/api/v1/users/me/settings', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            if (!res.ok) throw new Error('Save failed: HTTP ' + res.status);
            alert('Settings saved.');
        } catch (e) {
            alert('Could not save settings: ' + e.message);
        }
    });
})();
</script>
{/literal}
{/block}
