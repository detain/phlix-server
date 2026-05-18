/**
 * Theme Media Player - Browser autoplay policy handling
 *
 * Handles autoplay of theme.mp3 (audio) and backdrop.mp4 (video)
 * on the library browse page. Respects browser autoplay policy
 * by showing an overlay when autoplay is blocked.
 *
 * @since 0.14.0
 */
(function () {
    'use strict';

    /**
     * Minimum viewport width for backdrop video playback (1080px)
     * @type {number}
     */
    var BACKDROP_MIN_WIDTH = 1080;

    /**
     * Get DOM element for theme media player
     * @returns {Element|null}
     */
    function getThemeMediaPlayer() {
        return document.querySelector('.theme-media-player');
    }

    /**
     * Check if backdrop video should play based on viewport width
     * @returns {boolean}
     */
    function shouldPlayBackdrop() {
        return window.innerWidth >= BACKDROP_MIN_WIDTH;
    }

    /**
     * Create and configure audio element for theme audio
     * @param {string} audioUrl URL to stream audio from
     * @returns {HTMLAudioElement}
     */
    function createAudioElement(audioUrl) {
        var audio = new Audio();
        audio.src = audioUrl;
        audio.loop = true;
        audio.volume = 0.5;
        return audio;
    }

    /**
     * Create and configure video element for backdrop
     * @param {string} videoUrl URL to stream video from
     * @returns {HTMLVideoElement}
     */
    function createVideoElement(videoUrl) {
        var video = document.createElement('video');
        video.src = videoUrl;
        video.loop = true;
        video.muted = true;
        video.playsInline = true;
        video.volume = 0.3;
        return video;
    }

    /**
     * Show autoplay blocked overlay
     * @param {Element} player
     */
    function showAutoplayOverlay(player) {
        var overlay = player.querySelector('.theme-media-autoplay-overlay');
        if (overlay) {
            overlay.style.display = 'flex';
        }
    }

    /**
     * Hide autoplay blocked overlay
     * @param {Element} player
     */
    function hideAutoplayOverlay(player) {
        var overlay = player.querySelector('.theme-media-autoplay-overlay');
        if (overlay) {
            overlay.style.display = 'none';
        }
    }

    /**
     * Set up click handler to enable audio after user interaction
     * @param {HTMLAudioElement} audio
     * @param {Element} player
     */
    function setupClickToEnable(audio, player) {
        var toggleBtn = player.querySelector('.theme-media-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function () {
                audio.play().then(function () {
                    hideAutoplayOverlay(player);
                }).catch(function () {
                    // Still blocked
                });
            }, { once: true });
        }

        // Also allow clicking anywhere on player
        player.addEventListener('click', function () {
            audio.play().then(function () {
                hideAutoplayOverlay(player);
            }).catch(function () {
                // Still blocked
            });
        }, { once: true });
    }

    /**
     * Initialize theme media player
     */
    function init() {
        var player = getThemeMediaPlayer();
        if (!player) {
            return;
        }

        var audioUrl = player.dataset.audio;
        var videoUrl = player.dataset.video;
        var hasAudio = player.dataset.hasAudio === 'true';
        var hasVideo = player.dataset.hasVideo === 'true';

        var audio = null;
        var video = null;

        // Create audio element if audio theme exists
        if (hasAudio && audioUrl) {
            audio = createAudioElement(audioUrl);

            // Attempt autoplay
            audio.play().then(function () {
                hideAutoplayOverlay(player);
            }).catch(function () {
                // Autoplay blocked by browser policy
                showAutoplayOverlay(player);
                setupClickToEnable(audio, player);
            });
        }

        // Create and start backdrop video if viewport is large enough
        if (hasVideo && videoUrl && shouldPlayBackdrop()) {
            video = createVideoElement(videoUrl);

            // Position video as background
            video.style.position = 'fixed';
            video.style.top = '0';
            video.style.left = '0';
            video.style.width = '100%';
            video.style.height = '100%';
            video.style.objectFit = 'cover';
            video.style.zIndex = '-1';
            video.style.opacity = '0.6';

            document.body.insertBefore(video, document.body.firstChild);
            video.play().catch(function () {
                // Video autoplay may also be blocked (though muted should work)
            });
        }

        // Handle window resize to show/hide backdrop video
        var resizeTimer = null;
        window.addEventListener('resize', function () {
            if (resizeTimer) {
                clearTimeout(resizeTimer);
            }
            resizeTimer = setTimeout(function () {
                if (video) {
                    if (shouldPlayBackdrop()) {
                        if (!video.parentNode) {
                            document.body.insertBefore(video, document.body.firstChild);
                            video.play().catch(function () {});
                        }
                    } else {
                        if (video.parentNode) {
                            video.parentNode.removeChild(video);
                        }
                    }
                }
            }, 250);
        });

        // Toggle button functionality
        var toggleBtn = player.querySelector('.theme-media-toggle');
        if (toggleBtn && audio) {
            toggleBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (audio.paused) {
                    audio.play().then(function () {
                        toggleBtn.classList.add('playing');
                    }).catch(function () {});
                } else {
                    audio.pause();
                    toggleBtn.classList.remove('playing');
                }
            });
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
