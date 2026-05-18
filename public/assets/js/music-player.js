/**
 * Music Player JavaScript
 *
 * Handles music playback, queue management, and player controls.
 * Provides a complete audio player experience with play, pause,
 * seek, next/previous, shuffle, and repeat functionality.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @since 0.14.0
 */

(function() {
    'use strict';

    /**
     * MusicPlayer - Main music player class
     */
    class MusicPlayer {
        constructor() {
            // Audio element
            this.audio = new Audio();
            this.audio.preload = 'metadata';

            // State
            this.queue = [];
            this.currentIndex = -1;
            this.isPlaying = false;
            this.shuffle = false;
            this.repeat = 'none'; // 'none', 'all', 'one'
            this.volume = 0.8;

            // DOM elements
            this.elements = {};

            // Bind methods
            this.bindEvents();
        }

        /**
         * Initialize player and get DOM elements
         */
        init() {
            this.elements = {
                playBtn: document.getElementById('btn-play'),
                prevBtn: document.getElementById('btn-prev'),
                nextBtn: document.getElementById('btn-next'),
                shuffleBtn: document.getElementById('btn-shuffle'),
                repeatBtn: document.getElementById('btn-repeat'),
                progressFill: document.getElementById('progress-fill'),
                progressHandle: document.getElementById('progress-handle'),
                progressBar: document.querySelector('.progress-bar'),
                progressCurrent: document.getElementById('progress-current'),
                progressTotal: document.getElementById('progress-total'),
                trackName: document.getElementById('player-track-name'),
                artistName: document.getElementById('player-artist-name'),
                albumName: document.getElementById('player-album-name'),
                albumArt: document.getElementById('player-album-art'),
                volumeSlider: document.getElementById('volume-slider'),
                volumeIcon: document.getElementById('volume-icon'),
                queueList: document.getElementById('queue-list'),
            };

            this.loadSettings();
            this.updateUI();
        }

        /**
         * Bind DOM event listeners
         */
        bindEvents() {
            // Play/Pause
            if (this.elements.playBtn) {
                this.elements.playBtn.addEventListener('click', () => this.togglePlay());
            }

            // Previous
            if (this.elements.prevBtn) {
                this.elements.prevBtn.addEventListener('click', () => this.playPrevious());
            }

            // Next
            if (this.elements.nextBtn) {
                this.elements.nextBtn.addEventListener('click', () => this.playNext());
            }

            // Shuffle
            if (this.elements.shuffleBtn) {
                this.elements.shuffleBtn.addEventListener('click', () => this.toggleShuffle());
            }

            // Repeat
            if (this.elements.repeatBtn) {
                this.elements.repeatBtn.addEventListener('click', () => this.toggleRepeat());
            }

            // Volume
            if (this.elements.volumeSlider) {
                this.elements.volumeSlider.addEventListener('input', (e) => this.setVolume(e.target.value / 100));
            }

            // Progress bar seek
            if (this.elements.progressBar) {
                this.elements.progressBar.addEventListener('click', (e) => this.seek(e));
            }

            // Audio events
            this.audio.addEventListener('timeupdate', () => this.onTimeUpdate());
            this.audio.addEventListener('loadedmetadata', () => this.onLoadedMetadata());
            this.audio.addEventListener('ended', () => this.onEnded());
            this.audio.addEventListener('play', () => this.onPlay());
            this.audio.addEventListener('pause', () => this.onPause());
            this.audio.addEventListener('error', (e) => this.onError(e));

            // Keyboard shortcuts
            document.addEventListener('keydown', (e) => this.handleKeyboard(e));

            // Track row clicks
            document.addEventListener('click', (e) => {
                const trackRow = e.target.closest('.track-row');
                if (trackRow && e.target.closest('.play-track-btn')) {
                    const trackPath = trackRow.dataset.trackPath;
                    if (trackPath) {
                        this.playTrack(trackPath);
                    }
                }
            });
        }

        /**
         * Load player settings from localStorage
         */
        loadSettings() {
            try {
                const settings = JSON.parse(localStorage.getItem('phlex_music_settings') || '{}');
                this.volume = settings.volume ?? 0.8;
                this.shuffle = settings.shuffle ?? false;
                this.repeat = settings.repeat ?? 'none';
                this.audio.volume = this.volume;
            } catch (e) {
                // Use defaults
            }
        }

        /**
         * Save player settings to localStorage
         */
        saveSettings() {
            try {
                localStorage.setItem('phlex_music_settings', JSON.stringify({
                    volume: this.volume,
                    shuffle: this.shuffle,
                    repeat: this.repeat,
                }));
            } catch (e) {
                // Ignore
            }
        }

        /**
         * Play a track by path
         * @param {string} trackPath - Path to the audio file
         */
        playTrack(trackPath) {
            const trackIndex = this.queue.findIndex(t => t.path === trackPath);
            if (trackIndex >= 0) {
                this.currentIndex = trackIndex;
                this.loadCurrentTrack();
                this.audio.play();
            } else {
                // Add to queue and play
                this.queue.push({ path: trackPath });
                this.currentIndex = this.queue.length - 1;
                this.loadCurrentTrack();
                this.audio.play();
            }
        }

        /**
         * Load current track into audio element
         */
        loadCurrentTrack() {
            if (this.currentIndex < 0 || this.currentIndex >= this.queue.length) {
                return;
            }

            const track = this.queue[this.currentIndex];
            this.audio.src = track.path;
            this.updateUI();
            this.reportProgress();
        }

        /**
         * Toggle play/pause
         */
        togglePlay() {
            if (this.audio.paused) {
                if (this.audio.src) {
                    this.audio.play();
                } else if (this.queue.length > 0 && this.currentIndex < 0) {
                    this.currentIndex = 0;
                    this.loadCurrentTrack();
                    this.audio.play();
                }
            } else {
                this.audio.pause();
            }
        }

        /**
         * Play previous track
         */
        playPrevious() {
            if (this.audio.currentTime > 3) {
                // Restart current track if more than 3 seconds in
                this.audio.currentTime = 0;
            } else if (this.currentIndex > 0) {
                this.currentIndex--;
                this.loadCurrentTrack();
                this.audio.play();
            }
        }

        /**
         * Play next track
         */
        playNext() {
            if (this.shuffle) {
                this.playRandom();
            } else if (this.currentIndex < this.queue.length - 1) {
                this.currentIndex++;
                this.loadCurrentTrack();
                this.audio.play();
            } else if (this.repeat === 'all') {
                this.currentIndex = 0;
                this.loadCurrentTrack();
                this.audio.play();
            }
        }

        /**
         * Play a random track
         */
        playRandom() {
            if (this.queue.length <= 1) return;
            let newIndex;
            do {
                newIndex = Math.floor(Math.random() * this.queue.length);
            } while (newIndex === this.currentIndex);
            this.currentIndex = newIndex;
            this.loadCurrentTrack();
            this.audio.play();
        }

        /**
         * Toggle shuffle mode
         */
        toggleShuffle() {
            this.shuffle = !this.shuffle;
            this.updateShuffleUI();
            this.saveSettings();
        }

        /**
         * Toggle repeat mode
         */
        toggleRepeat() {
            const modes = ['none', 'all', 'one'];
            const currentIndex = modes.indexOf(this.repeat);
            this.repeat = modes[(currentIndex + 1) % modes.length];
            this.updateRepeatUI();
            this.saveSettings();
        }

        /**
         * Set volume (0-1)
         * @param {number} volume
         */
        setVolume(volume) {
            this.volume = Math.max(0, Math.min(1, volume));
            this.audio.volume = this.volume;
            this.updateVolumeUI();
            this.saveSettings();
        }

        /**
         * Seek to position in current track
         * @param {Event} e - Click event
         */
        seek(e) {
            if (!this.audio.duration) return;
            const rect = this.elements.progressBar.getBoundingClientRect();
            const percent = (e.clientX - rect.left) / rect.width;
            this.audio.currentTime = percent * this.audio.duration;
        }

        /**
         * Handle time update
         */
        onTimeUpdate() {
            if (!this.audio.duration) return;

            const percent = (this.audio.currentTime / this.audio.duration) * 100;
            if (this.elements.progressFill) {
                this.elements.progressFill.style.width = percent + '%';
            }
            if (this.elements.progressHandle) {
                this.elements.progressHandle.style.left = percent + '%';
            }
            if (this.elements.progressCurrent) {
                this.elements.progressCurrent.textContent = this.formatTime(this.audio.currentTime);
            }
        }

        /**
         * Handle metadata loaded
         */
        onLoadedMetadata() {
            if (this.elements.progressTotal) {
                this.elements.progressTotal.textContent = this.formatTime(this.audio.duration);
            }
        }

        /**
         * Handle track ended
         */
        onEnded() {
            if (this.repeat === 'one') {
                this.audio.currentTime = 0;
                this.audio.play();
            } else {
                this.playNext();
            }
        }

        /**
         * Handle play event
         */
        onPlay() {
            this.isPlaying = true;
            this.updatePlayUI();
        }

        /**
         * Handle pause event
         */
        onPause() {
            this.isPlaying = false;
            this.updatePlayUI();
        }

        /**
         * Handle audio error
         * @param {Event} e
         */
        onError(e) {
            console.error('Audio error:', e);
            this.playNext();
        }

        /**
         * Handle keyboard shortcuts
         * @param {KeyboardEvent} e
         */
        handleKeyboard(e) {
            // Ignore if typing in input
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

            switch (e.code) {
                case 'Space':
                    e.preventDefault();
                    this.togglePlay();
                    break;
                case 'ArrowLeft':
                    if (e.shiftKey) {
                        this.playPrevious();
                    } else {
                        this.audio.currentTime = Math.max(0, this.audio.currentTime - 10);
                    }
                    break;
                case 'ArrowRight':
                    if (e.shiftKey) {
                        this.playNext();
                    } else {
                        this.audio.currentTime = Math.min(this.audio.duration, this.audio.currentTime + 10);
                    }
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    this.setVolume(this.volume + 0.1);
                    break;
                case 'ArrowDown':
                    e.preventDefault();
                    this.setVolume(this.volume - 0.1);
                    break;
                case 'KeyM':
                    this.toggleMute();
                    break;
                case 'KeyS':
                    this.toggleShuffle();
                    break;
                case 'KeyR':
                    this.toggleRepeat();
                    break;
            }
        }

        /**
         * Toggle mute
         */
        toggleMute() {
            if (this.audio.volume > 0) {
                this._previousVolume = this.audio.volume;
                this.setVolume(0);
            } else {
                this.setVolume(this._previousVolume || 0.8);
            }
        }

        /**
         * Format seconds as MM:SS
         * @param {number} seconds
         * @returns {string}
         */
        formatTime(seconds) {
            if (!seconds || !isFinite(seconds)) return '0:00';
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return mins + ':' + secs.toString().padStart(2, '0');
        }

        /**
         * Update all UI elements
         */
        updateUI() {
            this.updatePlayUI();
            this.updateShuffleUI();
            this.updateRepeatUI();
            this.updateVolumeUI();
            this.updateQueueUI();

            if (this.currentIndex >= 0 && this.currentIndex < this.queue.length) {
                const track = this.queue[this.currentIndex];
                if (this.elements.trackName) {
                    this.elements.trackName.textContent = track.name || 'Unknown Track';
                }
                if (this.elements.artistName) {
                    this.elements.artistName.textContent = track.artist || '-';
                }
                if (this.elements.albumName) {
                    this.elements.albumName.textContent = track.album || '-';
                }
            }
        }

        /**
         * Update play/pause button
         */
        updatePlayUI() {
            if (this.elements.playBtn) {
                this.elements.playBtn.textContent = this.isPlaying ? '⏸' : '▶';
            }
        }

        /**
         * Update shuffle button
         */
        updateShuffleUI() {
            if (this.elements.shuffleBtn) {
                this.elements.shuffleBtn.style.color = this.shuffle ? '#6b4d8a' : '#fff';
            }
        }

        /**
         * Update repeat button
         */
        updateRepeatUI() {
            if (this.elements.repeatBtn) {
                switch (this.repeat) {
                    case 'none':
                        this.elements.repeatBtn.textContent = '🔁';
                        this.elements.repeatBtn.style.color = '#fff';
                        break;
                    case 'all':
                        this.elements.repeatBtn.textContent = '🔁';
                        this.elements.repeatBtn.style.color = '#6b4d8a';
                        break;
                    case 'one':
                        this.elements.repeatBtn.textContent = '🔂';
                        this.elements.repeatBtn.style.color = '#6b4d8a';
                        break;
                }
            }
        }

        /**
         * Update volume UI
         */
        updateVolumeUI() {
            if (this.elements.volumeSlider) {
                this.elements.volumeSlider.value = this.volume * 100;
            }
            if (this.elements.volumeIcon) {
                if (this.volume === 0) {
                    this.elements.volumeIcon.textContent = '🔇';
                } else if (this.volume < 0.5) {
                    this.elements.volumeIcon.textContent = '🔉';
                } else {
                    this.elements.volumeIcon.textContent = '🔊';
                }
            }
        }

        /**
         * Update queue list UI
         */
        updateQueueUI() {
            if (!this.elements.queueList) return;

            this.elements.queueList.innerHTML = this.queue.map((track, index) => `
                <div class="queue-item ${index === this.currentIndex ? 'active' : ''}" data-index="${index}">
                    <div class="queue-item-art"></div>
                    <div class="queue-item-info">
                        <div class="queue-item-name">${track.name || 'Unknown'}</div>
                        <div class="queue-item-artist">${track.artist || ''}</div>
                    </div>
                </div>
            `).join('');

            // Bind queue item clicks
            this.elements.queueList.querySelectorAll('.queue-item').forEach(item => {
                item.addEventListener('click', () => {
                    const index = parseInt(item.dataset.index, 10);
                    this.currentIndex = index;
                    this.loadCurrentTrack();
                    this.audio.play();
                });
            });
        }

        /**
         * Report playback progress to server
         */
        reportProgress() {
            if (!this.audio.duration || !this.audio.src) return;

            // Debounced progress reporting
            clearTimeout(this._reportTimeout);
            this._reportTimeout = setTimeout(() => {
                const progress = {
                    position: Math.floor(this.audio.currentTime * 10000000), // ticks
                    duration: Math.floor(this.audio.duration * 10000000),
                    percent: (this.audio.currentTime / this.audio.duration) * 100,
                };

                // Could send to server here if needed
                if (window.PhlexApp && window.PhlexApp.Player) {
                    window.PhlexApp.Player.reportProgress(progress);
                }
            }, 30000); // Report every 30 seconds
        }
    }

    // Create global instance
    window.MusicPlayer = new MusicPlayer();

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => window.MusicPlayer.init());
    } else {
        window.MusicPlayer.init();
    }

    /**
     * PhlexApp Music Namespace
     */
    if (!window.PhlexApp) window.PhlexApp = {};
    window.PhlexApp.Music = {
        player: window.MusicPlayer,

        /**
         * Play an album
         * @param {string} albumName
         */
        playAlbum: function(albumName) {
            // Fetch album tracks and add to player
            fetch(`/music/albums/${encodeURIComponent(albumName)}`)
                .then(r => r.json())
                .then(data => {
                    if (data.album && data.album.tracks) {
                        this.player.queue = data.album.tracks.map(t => ({
                            path: t.path,
                            name: t.name || t.metadata?.title,
                            artist: t.metadata?.artist,
                            album: t.metadata?.album,
                        }));
                        this.player.currentIndex = 0;
                        this.player.loadCurrentTrack();
                        this.player.audio.play();
                    }
                });
        },

        /**
         * Play all tracks by artist
         * @param {string} artistName
         */
        playArtist: function(artistName) {
            fetch(`/music/artists/${encodeURIComponent(artistName)}`)
                .then(r => r.json())
                .then(data => {
                    if (data.artist && data.artist.tracks) {
                        this.player.queue = data.artist.tracks.map(t => ({
                            path: t.path,
                            name: t.name || t.metadata?.title,
                            artist: t.metadata?.artist,
                            album: t.metadata?.album,
                        }));
                        this.player.currentIndex = 0;
                        this.player.loadCurrentTrack();
                        this.player.audio.play();
                    }
                });
        },
    };

})();
