{**
 * Newsletter Email Template
 *
 * Weekly watch report email sent to users containing:
 * - Watch time summary
 * - Top 5 media of the week with posters
 * - New items added this week
 * - View in Phlex CTA button
 * - Unsubscribe link
 *
 * @author Phlex Team
 * @version 1.0.0
 *}

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject|default:'Your Weekly Watch Report'}</title>
    <style type="text/css">
        /* Reset styles */
        body, html {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f5f5f5;
        }

        /* Email wrapper */
        .email-wrapper {
            width: 100%;
            background-color: #f5f5f5;
            padding: 20px 0;
        }

        /* Content container */
        .email-content {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        /* Header */
        .email-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            padding: 30px;
            text-align: center;
        }

        .email-header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }

        .email-header p {
            margin: 10px 0 0;
            opacity: 0.9;
            font-size: 14px;
        }

        /* Body content */
        .email-body {
            padding: 30px;
        }

        /* Stats section */
        .stats-section {
            display: flex;
            justify-content: space-around;
            margin-bottom: 30px;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #667eea;
        }

        .stat-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }

        /* Section titles */
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #333333;
            margin: 0 0 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
        }

        /* Top media section */
        .top-media {
            margin-bottom: 30px;
        }

        .media-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }

        .media-item {
            flex: 1 1 calc(33.333% - 10px);
            min-width: 150px;
            text-align: center;
        }

        .media-poster {
            width: 100%;
            max-width: 120px;
            height: 180px;
            object-fit: cover;
            border-radius: 6px;
            margin-bottom: 8px;
            background-color: #e9ecef;
        }

        .media-poster-placeholder {
            width: 100%;
            max-width: 120px;
            height: 180px;
            border-radius: 6px;
            margin-bottom: 8px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 32px;
        }

        .media-name {
            font-size: 14px;
            font-weight: 500;
            color: #333333;
            margin-bottom: 4px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .media-plays {
            font-size: 12px;
            color: #6c757d;
        }

        /* New items section */
        .new-items-section {
            margin-bottom: 30px;
            padding: 20px;
            background-color: #f0f7ff;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }

        .new-items-count {
            font-size: 36px;
            font-weight: 700;
            color: #667eea;
        }

        .new-items-label {
            font-size: 14px;
            color: #6c757d;
            margin-top: 4px;
        }

        /* CTA Button */
        .cta-section {
            text-align: center;
            margin: 30px 0;
        }

        .cta-button {
            display: inline-block;
            padding: 14px 32px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 16px;
        }

        .cta-button:hover {
            opacity: 0.9;
        }

        /* Footer */
        .email-footer {
            background-color: #f8f9fa;
            padding: 20px 30px;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }

        .footer-links {
            margin-bottom: 15px;
        }

        .footer-links a {
            color: #667eea;
            text-decoration: none;
            margin: 0 10px;
            font-size: 14px;
        }

        .footer-links a:hover {
            text-decoration: underline;
        }

        .footer-text {
            font-size: 12px;
            color: #6c757d;
            margin: 0;
        }

        .unsubscribe-link {
            color: #6c757d;
            text-decoration: underline;
            font-size: 12px;
        }

        /* Responsive */
        @media (max-width: 480px) {
            .email-body {
                padding: 20px;
            }

            .stats-section {
                flex-direction: column;
                gap: 15px;
            }

            .media-item {
                flex: 1 1 calc(50% - 8px);
            }

            .media-poster, .media-poster-placeholder {
                height: 140px;
            }
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="email-content">
            <!-- Header -->
            <div class="email-header">
                <h1>{$subject|default:'Your Weekly Watch Report'}</h1>
                <p>{$week_start|default:''} - {$week_end|default:''}</p>
            </div>

            <!-- Body -->
            <div class="email-body">
                <!-- Stats Section -->
                <div class="stats-section">
                    <div class="stat-item">
                        <div class="stat-value">{$week_watch_time_hours|default:'0'}</div>
                        <div class="stat-label">Hours Watched</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">{$new_items_count|default:'0'}</div>
                        <div class="stat-label">New Items</div>
                    </div>
                </div>

                <!-- Top Media Section -->
                {if $top_media|@count > 0}
                <div class="top-media">
                    <h2 class="section-title">Your Top Media This Week</h2>
                    <div class="media-grid">
                        {foreach item=$media from=$top_media key=key}
                        <div class="media-item">
                            {if $media.poster_url}
                            <img
                                src="{$media.poster_url}"
                                alt="{$media.name|escape:'html'}"
                                class="media-poster"
                            />
                            {else}
                            <div class="media-poster-placeholder">
                                {if $media.name|strlen > 1}
                                {$media.name|substr:0:1|upper}
                                {else}
                                ?
                                {/if}
                            </div>
                            {/if}
                            <div class="media-name">{$media.name|escape:'html'}</div>
                            <div class="media-plays">{$media.play_count} {if $media.play_count == 1}play{else}plays{/if}</div>
                        </div>
                        {/foreach}
                    </div>
                </div>
                {/if}

                <!-- New Items Section -->
                <div class="new-items-section">
                    <div class="new-items-count">{$new_items_count|default:'0'}</div>
                    <div class="new-items-label">new items added to the library this week</div>
                </div>

                <!-- CTA Section -->
                <div class="cta-section">
                    <a href="#" class="cta-button">View in Phlex</a>
                </div>
            </div>

            <!-- Footer -->
            <div class="email-footer">
                <div class="footer-links">
                    <a href="#">Visit Phlex</a>
                    <a href="#">Help Center</a>
                    <a href="#">Preferences</a>
                </div>
                <p class="footer-text">
                    You're receiving this email because you have an active Phlex account.
                    <br />
                    <a href="#" class="unsubscribe-link">Unsubscribe</a> from these emails.
                </p>
                <p class="footer-text">
                    &copy; {$year|default:'2024'} Phlex Media Server. All rights reserved.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
