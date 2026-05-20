/**
 * Photo Slideshow Controller
 *
 * Handles auto-advance interval, keyboard navigation (left/right/escape),
 * touch/swipe support, and play/pause functionality.
 *
 * @since 0.16.0
 */
(function(global) {
    'use strict';

    const SlideshowController = function(options) {
        this.options = Object.assign({
            interval: 5000,
            autoPlay: true
        }, options || {});

        this.currentIndex = 0;
        this.slides = [];
        this.timer = null;
        this.isPlaying = true;
        this.touchStartX = 0;
        this.touchEndX = 0;

        this.init();
    };

    SlideshowController.prototype.init = function() {
        // Get DOM elements
        this.imageEl = document.getElementById('slideshow-image');
        this.captionEl = document.getElementById('slideshow-caption');
        this.currentSlideEl = document.getElementById('current-slide');
        this.totalSlidesEl = document.getElementById('total-slides');
        this.prevBtn = document.getElementById('prev-btn');
        this.nextBtn = document.getElementById('next-btn');
        this.playPauseBtn = document.getElementById('play-pause-btn');
        this.exitBtn = document.getElementById('exit-btn');
        this.thumbnailBtns = document.querySelectorAll('.thumbnail-btn');

        // Get slides data from thumbnails
        this.slides = Array.from(this.thumbnailBtns).map(function(btn) {
            return {
                src: btn.dataset.src,
                index: parseInt(btn.dataset.index, 10)
            };
        });

        if (this.totalSlidesEl) {
            this.totalSlidesEl.textContent = this.slides.length;
        }

        // Bind events
        this.bindEvents();

        // Show first slide
        if (this.slides.length > 0) {
            this.showSlide(0);

            if (this.options.autoPlay) {
                this.startTimer();
            }
        }
    };

    SlideshowController.prototype.bindEvents = function() {
        const self = this;

        // Navigation buttons
        if (this.prevBtn) {
            this.prevBtn.addEventListener('click', function() {
                self.prev();
            });
        }

        if (this.nextBtn) {
            this.nextBtn.addEventListener('click', function() {
                self.next();
            });
        }

        if (this.playPauseBtn) {
            this.playPauseBtn.addEventListener('click', function() {
                self.togglePlayPause();
            });
        }

        if (this.exitBtn) {
            this.exitBtn.addEventListener('click', function() {
                self.exit();
            });
        }

        // Thumbnail clicks
        this.thumbnailBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                const index = parseInt(this.dataset.index, 10);
                self.goTo(index);
            });
        });

        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            switch (e.key) {
                case 'ArrowLeft':
                    self.prev();
                    break;
                case 'ArrowRight':
                    self.next();
                    break;
                case 'Escape':
                    self.exit();
                    break;
                case ' ':
                    e.preventDefault();
                    self.togglePlayPause();
                    break;
            }
        });

        // Touch/swipe support
        const container = document.querySelector('.slideshow-container');
        if (container) {
            container.addEventListener('touchstart', function(e) {
                self.touchStartX = e.changedTouches[0].screenX;
            }, { passive: true });

            container.addEventListener('touchend', function(e) {
                self.touchEndX = e.changedTouches[0].screenX;
                self.handleSwipe();
            }, { passive: true });
        }
    };

    SlideshowController.prototype.handleSwipe = function() {
        const diff = this.touchStartX - this.touchEndX;
        const threshold = 50;

        if (diff > threshold) {
            this.next();
        } else if (diff < -threshold) {
            this.prev();
        }
    };

    SlideshowController.prototype.showSlide = function(index) {
        // Clamp index
        if (index < 0) {
            index = this.slides.length - 1;
        } else if (index >= this.slides.length) {
            index = 0;
        }

        this.currentIndex = index;
        const slide = this.slides[index];

        if (slide && this.imageEl) {
            this.imageEl.src = slide.src;
            this.imageEl.alt = 'Slide ' + (index + 1);
        }

        if (this.currentSlideEl) {
            this.currentSlideEl.textContent = index + 1;
        }

        // Update caption from data attribute
        const thumbnail = this.thumbnailBtns[index];
        if (thumbnail) {
            const img = thumbnail.querySelector('img');
            if (img && this.captionEl) {
                this.captionEl.textContent = img.alt || '';
            }
        }

        // Update active thumbnail
        this.thumbnailBtns.forEach(function(btn, i) {
            btn.classList.toggle('active', i === index);
        });

        // Scroll active thumbnail into view
        if (thumbnail) {
            thumbnail.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }
    };

    SlideshowController.prototype.prev = function() {
        this.showSlide(this.currentIndex - 1);
        this.resetTimer();
    };

    SlideshowController.prototype.next = function() {
        this.showSlide(this.currentIndex + 1);
        this.resetTimer();
    };

    SlideshowController.prototype.goTo = function(index) {
        this.showSlide(index);
        this.resetTimer();
    };

    SlideshowController.prototype.startTimer = function() {
        const self = this;
        this.timer = setInterval(function() {
            self.next();
        }, this.options.interval);
        this.isPlaying = true;
        this.updatePlayPauseButton();
    };

    SlideshowController.prototype.stopTimer = function() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
        this.isPlaying = false;
        this.updatePlayPauseButton();
    };

    SlideshowController.prototype.resetTimer = function() {
        if (this.isPlaying) {
            this.stopTimer();
            this.startTimer();
        }
    };

    SlideshowController.prototype.togglePlayPause = function() {
        if (this.isPlaying) {
            this.stopTimer();
        } else {
            this.startTimer();
        }
    };

    SlideshowController.prototype.updatePlayPauseButton = function() {
        if (this.playPauseBtn) {
            this.playPauseBtn.textContent = this.isPlaying ? '⏸' : '▶';
            this.playPauseBtn.title = this.isPlaying ? 'Pause' : 'Play';
        }
    };

    SlideshowController.prototype.exit = function() {
        this.stopTimer();
        // Navigate back to album
        const referer = document.referrer;
        if (referer && referer.includes('/photo/')) {
            window.history.back();
        } else {
            window.location.href = '/photo/albums';
        }
    };

    // Auto-initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        const slideshowPage = document.querySelector('.slideshow-page');
        if (slideshowPage) {
            const interval = parseInt(slideshowPage.dataset.interval, 10) || 5000;
            global.phlixSlideshow = new SlideshowController({
                interval: interval * 1000
            });
        }
    });

    // Export to global
    global.SlideshowController = SlideshowController;

})(window);
