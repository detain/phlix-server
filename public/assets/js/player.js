/**
 * Phlix Media Server - Video Player
 */

class PhlixPlayer {
    constructor(videoElement) {
        this.video = videoElement;
        this.overlay = document.getElementById('player-overlay');
        this.playPauseBtn = document.getElementById('play-pause');
        this.progressBar = document.getElementById('progress-bar');
        this.currentTimeEl = document.getElementById('current-time');
        this.durationEl = document.getElementById('duration');

        this.hideControlsTimeout = null;
        this.isFullscreen = false;

        this.init();
    }

    init() {
        // Play/Pause
        if (this.playPauseBtn) {
            this.playPauseBtn.addEventListener('click', () => this.togglePlayPause());
        }

        // Video click to toggle play/pause and show controls
        this.video.addEventListener('click', () => this.togglePlayPause());

        // Progress bar
        if (this.progressBar) {
            this.progressBar.addEventListener('input', (e) => this.seek(e.target.value));
        }

        // Update progress as video plays
        this.video.addEventListener('timeupdate', () => this.updateProgress());

        // Video ends
        this.video.addEventListener('ended', () => this.onEnded());

        // Show controls on mouse move
        document.addEventListener('mousemove', () => this.showControls());

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => this.handleKeydown(e));

        // Fullscreen change
        document.addEventListener('fullscreenchange', () => this.onFullscreenChange());

        // Skip buttons
        document.querySelectorAll('.control-skip-back').forEach(btn => {
            btn.addEventListener('click', () => this.skip(-10));
        });

        document.querySelectorAll('.control-skip-forward').forEach(btn => {
            btn.addEventListener('click', () => this.skip(10));
        });

        // Start hide controls timer
        this.startHideControlsTimer();
    }

    togglePlayPause() {
        if (this.video.paused) {
            this.video.play();
            if (this.playPauseBtn) this.playPauseBtn.textContent = '⏸';
        } else {
            this.video.pause();
            if (this.playPauseBtn) this.playPauseBtn.textContent = '▶';
        }
        this.showControls();
    }

    seek(percent) {
        const time = (percent / 100) * this.video.duration;
        this.video.currentTime = time;
    }

    updateProgress() {
        if (!this.video.duration) return;

        const percent = (this.video.currentTime / this.video.duration) * 100;
        if (this.progressBar) {
            this.progressBar.value = percent;
        }

        // Update time display
        if (this.currentTimeEl) {
            this.currentTimeEl.textContent = this.formatTime(this.video.currentTime);
        }
        if (this.durationEl) {
            this.durationEl.textContent = this.formatTime(this.video.duration);
        }
    }

    skip(seconds) {
        this.video.currentTime = Math.max(0, Math.min(
            this.video.duration,
            this.video.currentTime + seconds
        ));
    }

    showControls() {
        if (this.overlay) {
            this.overlay.style.opacity = '1';
        }
        this.startHideControlsTimer();
    }

    hideControls() {
        if (!this.video.paused && this.overlay) {
            this.overlay.style.opacity = '0';
        }
    }

    startHideControlsTimer() {
        clearTimeout(this.hideControlsTimeout);
        this.hideControlsTimeout = setTimeout(() => this.hideControls(), 3000);
    }

    onEnded() {
        if (this.playPauseBtn) this.playPauseBtn.textContent = '▶';

        // Report watched progress to server
        if (window.Player && this.video.dataset.itemId) {
            Player.markWatched(this.video.dataset.itemId).catch(console.error);
        }
    }

    handleKeydown(e) {
        switch (e.code) {
            case 'Space':
                e.preventDefault();
                this.togglePlayPause();
                break;
            case 'ArrowLeft':
                e.preventDefault();
                this.skip(-10);
                break;
            case 'ArrowRight':
                e.preventDefault();
                this.skip(10);
                break;
            case 'ArrowUp':
                e.preventDefault();
                this.video.volume = Math.min(1, this.video.volume + 0.1);
                break;
            case 'ArrowDown':
                e.preventDefault();
                this.video.volume = Math.max(0, this.video.volume - 0.1);
                break;
            case 'KeyF':
                e.preventDefault();
                this.toggleFullscreen();
                break;
            case 'KeyM':
                e.preventDefault();
                this.video.muted = !this.video.muted;
                break;
            case 'Escape':
                if (this.isFullscreen) {
                    this.toggleFullscreen();
                }
                break;
        }
    }

    toggleFullscreen() {
        const playerPage = document.querySelector('.player-page');
        if (!playerPage) return;

        if (!document.fullscreenElement) {
            playerPage.requestFullscreen().catch(err => {
                console.error('Fullscreen error:', err);
            });
        } else {
            document.exitFullscreen();
        }
    }

    onFullscreenChange() {
        this.isFullscreen = !!document.fullscreenElement;
    }

    formatTime(seconds) {
        if (!seconds || isNaN(seconds)) return '00:00';

        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = Math.floor(seconds % 60);

        if (h > 0) {
            return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
        }
        return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
    }
}

// Initialize player when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    const videoElement = document.getElementById('video-player');
    if (videoElement) {
        const player = new PhlixPlayer(videoElement);

        // Report progress periodically
        setInterval(() => {
            if (!videoElement.paused && videoElement.dataset.itemId) {
                Player.reportProgress(
                    videoElement.dataset.itemId,
                    videoElement.currentTime,
                    videoElement.duration
                ).catch(console.error);
            }
        }, 30000); // Every 30 seconds
    }
});
