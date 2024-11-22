(function($) {
  'use strict';

  class BlueSkyFeed {
    constructor(container) {
      this.container = $(container);
      this.scroller = this.container.find('.bluesky-feed-scroller');
      this.posts = this.container.find('.bluesky-post');
      this.scrollDirection = this.container.data('scroll-direction') || 'horizontal';
      this.currentPage = 1;
      this.isLoading = false;
      this.scrollTimeout = null;
      this.touchStartX = 0;
      this.touchStartY = 0;
      this.scrollLeft = 0;
      this.scrollTop = 0;

      this.init();
    }

    log(...args) {
      if (this.debug) {
        console.log('[BlueSkyFeed]', ...args);
      }
    }

    init() {

      // Only add navigation for horizontal scroll
      if (this.scrollDirection === 'horizontal') {
        this.addNavigation();
        this.handleTouch();
      }

      // Initialize scroll handling
      this.handleScroll();

      // Initialize lazy loading
      this.initLazyLoading();

      // Handle window resize
      this.handleResize();

      // Initial check of navigation state
      this.updateNavigationState();
    }

    addNavigation() {
      const nav = $('<div class="bluesky-nav-buttons"></div>');
      const prevButton = $('<button class="bluesky-nav-button bluesky-prev">←</button>');
      const nextButton = $('<button class="bluesky-nav-button bluesky-next">→</button>');

      nav.append(prevButton).append(nextButton);
      this.container.append(nav);

      // Handle navigation clicks
      prevButton.on('click', () => this.scrollPosts('prev'));
      nextButton.on('click', () => this.scrollPosts('next'));
    }

    scrollPosts(direction) {
      if (this.scrollDirection !== 'horizontal') return;

      const scrollAmount = this.container.width() * 0.8;
      const currentScroll = this.scroller.scrollLeft();

      const newScroll = direction === 'next'
        ? currentScroll + scrollAmount
        : currentScroll - scrollAmount;

      this.scroller.animate({
        scrollLeft: newScroll
      }, 300);
    }

    handleTouch() {
      this.scroller.on('touchstart', (e) => {
        this.touchStartX = e.originalEvent.touches[0].pageX;
        this.scrollLeft = this.scroller.scrollLeft();
      });

      this.scroller.on('touchmove', (e) => {
        if (!this.touchStartX) return;

        const touchX = e.originalEvent.touches[0].pageX;
        const diff = this.touchStartX - touchX;
        this.scroller.scrollLeft(this.scrollLeft + diff);
      });

      this.scroller.on('touchend', () => {
        this.touchStartX = null;
      });
    }

    updateNavigationState() {
      const scroller = this.scroller[0];
      const isAtStart = scroller.scrollLeft <= 0;
      const isAtEnd = scroller.scrollLeft >= (scroller.scrollWidth - scroller.clientWidth - 10);

      this.container.find('.bluesky-prev').prop('disabled', isAtStart);
      this.container.find('.bluesky-next').prop('disabled', isAtEnd);
    }

    handleScroll() {
      this.scroller.on('scroll', () => {
        if (this.scrollTimeout) {
          clearTimeout(this.scrollTimeout);
        }

        this.scrollTimeout = setTimeout(() => {
          if (this.scrollDirection === 'horizontal') {
            this.updateNavigationState();
          }
          this.checkForNewContent();
        }, 100);
      });
    }

    checkForNewContent() {
      if (this.isLoading) return;

      const scroller = this.scroller[0];
      let shouldLoadMore = false;

      if (this.scrollDirection === 'horizontal') {
        const scrollPercentage = (scroller.scrollLeft + scroller.clientWidth) / scroller.scrollWidth;
        shouldLoadMore = scrollPercentage > 0.8;
      } else {
        const scrollPercentage = (scroller.scrollTop + scroller.clientHeight) / scroller.scrollHeight;
        shouldLoadMore = scrollPercentage > 0.9;
      }

      if (shouldLoadMore) {
        this.loadMoreContent();
      }
    }

    async loadMoreContent() {
      this.isLoading = true;
      this.showLoader();

      try {
        const response = await $.ajax({
          url: ajaxurl,
          type: 'POST',
          data: {
            action: 'load_more_bluesky_posts',
            page: ++this.currentPage,
            nonce: blueSkySettings.nonce
          }
        });

        if (response.success && response.data.posts) {
          this.appendPosts(response.data.posts);
        }
      } catch (error) {
        console.error('Error loading more posts:', error);
      } finally {
        this.hideLoader();
        this.isLoading = false;
      }
    }

    createPostElement(post) {
      return `
                <div class="bluesky-post">
                    <div class="bluesky-post-header">
                        <img src="${post.author.avatar}" class="bluesky-avatar lazy" data-src="${post.author.avatar}" />
                        <div class="bluesky-author-info">
                            <span class="bluesky-display-name">${post.author.displayName}</span>
                            <span class="bluesky-handle">${post.author.handle}</span>
                        </div>
                    </div>
                    <div class="bluesky-post-content">${post.text}</div>
                    ${this.createImagesHTML(post.images)}
                    <div class="bluesky-post-footer">
                        <span class="bluesky-timestamp">${post.timestamp}</span>
                    </div>
                </div>
            `;
    }

    showLoader() {
      if (!this.container.find('.bluesky-loading').length) {
        const loader = $('<div class="bluesky-loading"><div class="bluesky-loading-spinner"></div></div>');
        this.container.append(loader);
      }
    }

    hideLoader() {
      this.container.find('.bluesky-loading').remove();
    }

    handleResize() {
      let resizeTimer;
      $(window).on('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
          this.updateNavigationState();
        }, 250);
      });
    }

    createImagesHTML(images) {
      console.log('Creating images HTML with:', images);

      if (!images || !images.length) {
        console.log('No images to display');
        return '';
      }

      console.log(`Creating HTML for ${images.length} images`);
      const imageGrid = images.length > 1 ? 'bluesky-post-images-grid' : '';

      const html = `
        <div class="bluesky-post-images ${imageGrid}">
            ${images.map((image, index) => {
        console.log(`Processing image ${index}:`, image);
        return `
                    <div class="bluesky-image-wrapper" data-index="${index}">
                        <img
                            src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
                            class="lazy bluesky-post-image"
                            data-src="${image}"
                            alt=""
                            onerror="console.error('Image load failed:', this.dataset.src); this.style.display='none';"
                            onload="console.log('Image loaded successfully:', this.dataset.src);"
                        />
                    </div>
                `;
      }).join('')}
        </div>
    `;

      console.log('Generated HTML:', html);
      return html;
    }

    initLazyLoading() {
      this.log('Initializing lazy loading');

      if (!('IntersectionObserver' in window)) {
        return;
      }

      const imageObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            const img = entry.target;
            if (img.dataset.src) {
              const tempImg = new Image();

              tempImg.onload = () => {
                img.src = img.dataset.src;
                img.classList.remove('lazy');
                img.classList.add('loaded');
                imageObserver.unobserve(img);
              };

              tempImg.onerror = () => {
                img.closest('.bluesky-image-wrapper').style.display = 'none';
              };

              tempImg.src = img.dataset.src;
            }
          }
        });
      }, {
        rootMargin: '50px 0px',
        threshold: 0.1
      });

      const lazyImages = this.container.find('img.lazy');
      this.log(`Found ${lazyImages.length} lazy images to observe`);

      lazyImages.each((index, img) => {
        this.log('Observing image:', img.dataset.src);
        imageObserver.observe(img);
      });
    }

    appendPosts(posts) {
      console.log('Appending posts:', posts);

      // Sort posts by date before appending
      posts.sort((a, b) => {
        const dateA = new Date(a.createdAt);
        const dateB = new Date(b.createdAt);
        return dateB - dateA;
      });

      posts.forEach((post, index) => {
        console.log(`Processing post ${index}:`, post);
        console.log(`Post images:`, post.images);
        const postElement = $(this.createPostElement(post));
        this.scroller.append(postElement);
      });

      this.posts = this.container.find('.bluesky-post');
      this.updateNavigationState();
      this.initLazyLoading();
    }
  }

  // Initialize on document ready
  $(document).ready(function() {
    $('.bluesky-feed-container').each(function() {
      new BlueSkyFeed(this);
    });
  });

})(jQuery);
