/**
 * Audiobook Player JavaScript
 *
 * Handles audiobook playback with chapter navigation, position persistence,
 * and progress tracking every 10 seconds.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @since 0.18.0
 */

(function() {
    'use strict';

    /**
     * AudiobookPlayer - Main audiobook player class
     */
    class AudiobookPlayer {
        constructor() {
            // Audio element
            this.audio = new Audio();
            this.audio.preload = 'metadata';

            // State
            this.audiobookId = null;
            this.chapters = [];
            this.currentChapterIndex = 0;
            this.positionMs = 0;
            this.completedChapters = {};
            this.isPlaying = false;
            this.saveInterval = null;

            // DOM elements
            this.elements = {};

            // Bind methods
            this.bindEvents();
        }

        /**
         * Initialize player with audiobook data
         * @param {string} audiobookId - Audiobook ID
         * @param {Array} chapters - Array of chapter objects
         */
        init(audiobookId, chapters) {
            this.audiobookId = audiobookId;
            this.chapters = chapters || [];

            this.elements = {
                playBtn: document.getElementById('btn-play'),
                skipBackBtn: document.getElementById('btn-skip-back'),
                skipForwardBtn: document.getElementById('btn-skip-forward'),
                progressFill: document.getElementById('progress-fill'),
                chapterProgress: document.getElementById('chapter-progress'),
                currentTime: document.getElementById('current-time'),
                totalTime: document.getElementById('total-time'),
                currentChapter: document.getElementById('current-chapter'),
                chapterList: document.getElementById('chapter-list'),
            };

            this.loadProgress();
            this.updateChapterList();
            this.startSaveInterval();
        }

        /**
         * Bind DOM event listeners
         */
        bindEvents() {
            // Play/Pause
            if (this.elements.playBtn) {
                this.elements.playBtn.addEventListener('click', () => this.togglePlay());
            }

            // Skip buttons
            if (this.elements.skipBackBtn) {
                this.elements.skipBackBtn.addEventListener('click', () => this.skip(-30));
            }
            if (this.elements.skipForwardBtn) {
                this.elements.skipForwardBtn.addEventListener('click', () => this.skip(30));
            }

            // Progress bar click
            const progressBar = document.querySelector('.player-progress-bar');
            if (progressBar) {
                progressBar.addEventListener('click', (e) => this.seek(e));
            }

            // Audio events
            this.audio.addEventListener('timeupdate', () => this.onTimeUpdate());
            this.audio.addEventListener('loadedmetadata', () => this.onLoadedMetadata());
            this.audio.addEventListener('ended', () => this.onEnded());
            this.audio.addEventListener('play', () => this.onPlay());
            this.audio.addEventListener('pause', () => this.onPause());

            // Chapter list clicks
            document.addEventListener('click', (e) => {
                const chapterItem = e.target.closest('.chapter-item');
                if (chapterItem) {
                    const index = parseInt(chapterItem.dataset.index, 10);
                    if (!isNaN(index)) {
                        this.playChapter(index);
                    }
                }
            });

            // Keyboard shortcuts
            document.addEventListener('keydown', (e) => this.handleKeyboard(e));
        }

        /**
         * Load saved progress from server
         */
        loadProgress() {
            if (!this.audiobookId) return;

            fetch(`/audiobooks/${this.audiobookId}/progress`)
                .then(r => r.json())
                .then(data => {
                    if (data.progress) {
                        this.positionMs = data.progress.position_ms || 0;
                        this.currentChapterIndex = data.progress.current_chapter_index || 0;
                        this.completedChapters = data.progress.completed_chapters || {};

                        // Resume from saved position
                        if (this.chapters[this.currentChapterIndex]) {
                            const chapter = this.chapters[this.currentChapterIndex];
                            const seekMs = (chapter.start_ms || 0) + this.positionMs;
                            this.audio.currentTime = seekMs / 1000;
                        }
                    }
                })
                .catch(err => {
                    console.warn('Failed to load progress:', err);
                });
        }

        /**
         * Save progress to server every 10 seconds
         */
        startSaveInterval() {
            this.saveInterval = setInterval(() => {
                this.saveProgress();
            }, 10000); // 10 seconds
        }

        /**
         * Save current progress to server
         */
        saveProgress() {
            if (!this.audiobookId || !this.isPlaying) return;

            const progress = {
                position_ms: this.positionMs,
                current_chapter_index: this.currentChapterIndex,
                completed_chapters: this.completedChapters,
                percent_complete: this.calculatePercentComplete(),
            };

            fetch(`/audiobooks/${this.audiobookId}/progress`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(progress),
            }).catch(err => {
                console.warn('Failed to save progress:', err);
            });
        }

        /**
         * Calculate percent complete based on current position
         */
        calculatePercentComplete() {
            if (this.chapters.length === 0) return 0;

            let totalMs = 0;
            let currentMs = 0;

            for (let i = 0; i < this.chapters.length; i++) {
                const chapter = this.chapters[i];
                const duration = (chapter.end_ms || 0) - (chapter.start_ms || 0);
                totalMs += duration;

                if (i < this.currentChapterIndex) {
                    currentMs += duration;
                } else if (i === this.currentChapterIndex) {
                    currentMs += this.positionMs;
                }
            }

            if (totalMs === 0) return 0;
            return Math.min(100, (currentMs / totalMs) * 100);
        }

        /**
         * Toggle play/pause
         */
        togglePlay() {
            if (this.audio.paused) {
                if (!this.audio.src) {
                    this.playChapter(this.currentChapterIndex);
                }
                this.audio.play();
            } else {
                this.audio.pause();
            }
        }

        /**
         * Play a specific chapter
         * @param {number} chapterIndex - Chapter index to play
         */
        playChapter(chapterIndex) {
            if (chapterIndex < 0 || chapterIndex >= this.chapters.length) return;

            this.currentChapterIndex = chapterIndex;
            const chapter = this.chapters[chapterIndex];
            const streamUrl = `/audiobooks/${this.audiobookId}/stream?chapter=${chapterIndex}&offset=0`;

            this.audio.src = streamUrl;
            this.updateChapterList();
            this.updateChapterInfo();
            this.audio.play();
        }

        /**
         * Skip forward/backward by seconds
         * @param {number} seconds - Seconds to skip (negative for back)
         */
        skip(seconds) {
            this.audio.currentTime = Math.max(0, Math.min(
                this.audio.duration || 0,
                this.audio.currentTime + seconds
            ));
        }

        /**
         * Seek to position in current chapter
         * @param {Event} e - Click event
         */
        seek(e) {
            if (!this.audio.duration) return;

            const rect = e.currentTarget.getBoundingClientRect();
            const percent = (e.clientX - rect.left) / rect.width;
            const seekTime = percent * this.audio.duration;

            // Calculate which chapter this falls into
            let accumulatedTime = 0;
            for (let i = 0; i < this.chapters.length; i++) {
                const chapter = this.chapters[i];
                const duration = ((chapter.end_ms || 0) - (chapter.start_ms || 0)) / 1000;

                if (seekTime <= accumulatedTime + duration) {
                    this.currentChapterIndex = i;
                    this.positionMs = Math.max(0, (seekTime - accumulatedTime) * 1000);
                    this.audio.currentTime = seekTime;
                    this.updateChapterList();
                    this.updateChapterInfo();
                    return;
                }
                accumulatedTime += duration;
            }
        }

        /**
         * Handle time update
         */
        onTimeUpdate() {
            if (!this.audio.duration) return;

            // Update current chapter based on time
            this.updateCurrentChapter();

            // Update progress UI
            const percent = (this.audio.currentTime / this.audio.duration) * 100;
            if (this.elements.progressFill) {
                this.elements.progressFill.style.width = percent + '%';
            }

            // Update chapter progress indicator
            if (this.chapters[this.currentChapterIndex]) {
                const chapter = this.chapters[this.currentChapterIndex];
                const chapterStart = (chapter.start_ms || 0) / 1000;
                const chapterDuration = ((chapter.end_ms || 0) - (chapter.start_ms || 0)) / 1000;
                const progress = this.audio.currentTime - chapterStart;
                const chapterPercent = Math.min(100, Math.max(0, (progress / chapterDuration) * 100));

                if (this.elements.chapterProgress) {
                    this.elements.chapterProgress.style.width = chapterPercent + '%';
                }

                this.positionMs = Math.max(0, progress * 1000);
            }

            // Update time display
            if (this.elements.currentTime) {
                this.elements.currentTime.textContent = this.formatTime(this.audio.currentTime);
            }
        }

        /**
         * Update current chapter based on audio time
         */
        updateCurrentChapter() {
            const currentTimeMs = this.audio.currentTime * 1000;

            for (let i = 0; i < this.chapters.length; i++) {
                const chapter = this.chapters[i];
                const startMs = chapter.start_ms || 0;
                const endMs = chapter.end_ms || startMs + 300000; // Default 5 min if no end

                if (currentTimeMs >= startMs && currentTimeMs < endMs) {
                    if (this.currentChapterIndex !== i) {
                        this.currentChapterIndex = i;
                        this.updateChapterList();
                        this.updateChapterInfo();
                    }

                    // Mark chapter as complete when near end
                    if (currentTimeMs >= endMs - 5000 && !this.completedChapters[i]) {
                        this.completedChapters[i] = endMs - startMs;
                    }
                    return;
                }
            }
        }

        /**
         * Handle metadata loaded
         */
        onLoadedMetadata() {
            if (this.elements.totalTime) {
                this.elements.totalTime.textContent = this.formatTime(this.audio.duration);
            }
        }

        /**
         * Handle track ended
         */
        onEnded() {
            // Mark current chapter complete
            if (!this.completedChapters[this.currentChapterIndex]) {
                const chapter = this.chapters[this.currentChapterIndex];
                if (chapter) {
                    this.completedChapters[this.currentChapterIndex] =
                        (chapter.end_ms || 0) - (chapter.start_ms || 0);
                }
            }

            // Play next chapter if available
            if (this.currentChapterIndex < this.chapters.length - 1) {
                this.playChapter(this.currentChapterIndex + 1);
            } else {
                this.isPlaying = false;
                if (this.elements.playBtn) {
                    this.elements.playBtn.textContent = '▶';
                }
            }
        }

        /**
         * Handle play event
         */
        onPlay() {
            this.isPlaying = true;
            if (this.elements.playBtn) {
                this.elements.playBtn.textContent = '⏸';
            }
        }

        /**
         * Handle pause event
         */
        onPause() {
            this.isPlaying = false;
            if (this.elements.playBtn) {
                this.elements.playBtn.textContent = '▶';
            }
        }

        /**
         * Update chapter list UI
         */
        updateChapterList() {
            if (!this.elements.chapterList) return;

            const items = this.elements.chapterList.querySelectorAll('.chapter-item');
            items.forEach((item, index) => {
                item.classList.toggle('active', index === this.currentChapterIndex);
            });
        }

        /**
         * Update current chapter info display
         */
        updateChapterInfo() {
            if (!this.elements.currentChapter) return;

            const chapter = this.chapters[this.currentChapterIndex];
            if (chapter) {
                this.elements.currentChapter.textContent = chapter.title || `Chapter ${this.currentChapterIndex + 1}`;
            }
        }

        /**
         * Handle keyboard shortcuts
         * @param {KeyboardEvent} e - Keyboard event
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
                    e.preventDefault();
                    if (e.shiftKey) {
                        this.playPreviousChapter();
                    } else {
                        this.skip(-10);
                    }
                    break;
                case 'ArrowRight':
                    e.preventDefault();
                    if (e.shiftKey) {
                        this.playNextChapter();
                    } else {
                        this.skip(10);
                    }
                    break;
                case 'KeyP':
                    // Previous chapter
                    this.playPreviousChapter();
                    break;
                case 'KeyN':
                    // Next chapter
                    this.playNextChapter();
                    break;
            }
        }

        /**
         * Play previous chapter
         */
        playPreviousChapter() {
            if (this.currentChapterIndex > 0) {
                this.playChapter(this.currentChapterIndex - 1);
            } else {
                // Restart current chapter
                const chapter = this.chapters[this.currentChapterIndex];
                if (chapter) {
                    this.audio.currentTime = (chapter.start_ms || 0) / 1000;
                }
            }
        }

        /**
         * Play next chapter
         */
        playNextChapter() {
            if (this.currentChapterIndex < this.chapters.length - 1) {
                this.playChapter(this.currentChapterIndex + 1);
            }
        }

        /**
         * Format seconds as HH:MM:SS or MM:SS
         * @param {number} seconds - Seconds to format
         * @returns {string} Formatted time string
         */
        formatTime(seconds) {
            if (!seconds || !isFinite(seconds)) return '0:00';

            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            const secs = Math.floor(seconds % 60);

            if (hours > 0) {
                return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
            }
            return `${minutes}:${secs.toString().padStart(2, '0')}`;
        }

        /**
         * Cleanup on destroy
         */
        destroy() {
            if (this.saveInterval) {
                clearInterval(this.saveInterval);
            }
            this.saveProgress();
        }
    }

    // Create global instance
    window.AudiobookPlayer = new AudiobookPlayer();

})();
