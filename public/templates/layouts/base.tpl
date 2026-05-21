<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{block name="title"}Phlix{/block} - Media Server</title>
    <link rel="stylesheet" href="/assets/css/main.css">
    {$theme_css nofilter}
    {block name="styles"}{/block}
</head>
<body>
    {block name="body"}
    <div class="app-container">
        {block name="content"}{/block}
    </div>
    {/block}

    <script src="/assets/js/app.js"></script>
    {$theme_js nofilter}
    {block name="scripts"}{/block}
</body>
</html>