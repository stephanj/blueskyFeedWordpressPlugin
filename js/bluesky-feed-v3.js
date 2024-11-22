(function($) {
  class BlueSkyFeed {
    constructor(container) {
      this.container = $(container);
      this.scroller = this.container.find('.bluesky-feed-scroller');
      this.page = 1;
      this.loading = false;
      this.noMorePosts = false;
      this.scrollDirection = this.container.data('scrollDirection') || 'horizontal';

      if (this.scrollDirection === 'horizontal') {
        this.handleTouch();
      }

      this.initLazyLoading();
      this.setupScrollHandling();
      this.loadInitialPosts();
      this.updateNavigationState();
    }

    loadInitialPosts() {
      const ajaxEndpoint = window.blueSkyFeed?.ajaxurl || '/wp-admin/admin-ajax.php';

      if (this.loading) return;
      this.loading = true;

      $.ajax({
        url: ajaxEndpoint,
        type: 'POST',
        data: {
          action: 'load_more_bluesky_posts',
          page: this.page,
          nonce: window.blueSkyFeed?.nonce || ''
        },
        success: (response) => {
          if (response.success && response.data.posts) {
            if (response.data.posts.length === 0) {
              this.noMorePosts = true;
            }
            this.updateNavigationState();
          }
        },
        error: (error) => {
          console.error('Error loading posts:', error);
        },
        complete: () => {
          this.loading = false;
        }
      });
    }

    initLazyLoading() {
      const options = {
        root: null,
        rootMargin: '50px',
        threshold: 0.1
      };

      const observer = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            const img = $(entry.target);
            if (img.data('src')) {
              img.attr('src', img.data('src'))
                  .removeClass('lazy');
              observer.unobserve(entry.target);
            }
          }
        });
      }, options);

      this.container.find('img.lazy').each((_, img) => {
        observer.observe(img);
      });
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

    setupScrollHandling() {
      if (this.scrollDirection === 'horizontal') {
        const prevButton = $('<button>')
            .addClass('bluesky-nav-button prev')
            .html('&larr;')
            .on('click', () => this.scrollPosts('prev'));

        const nextButton = $('<button>')
            .addClass('bluesky-nav-button next')
            .html('&rarr;')
            .on('click', () => this.scrollPosts('next'));

        this.container.append(prevButton, nextButton);
        this.updateNavigationState();

        this.scroller.on('scroll', () => {
          this.updateNavigationState();
        });
      } else {
        const observer = new IntersectionObserver(
            (entries) => {
              if (entries[0].isIntersecting && !this.loading && !this.noMorePosts) {
                this.loadInitialPosts();
              }
            },
            { threshold: 0.5 }
        );

        const posts = this.container.find('.bluesky-post');
        if (posts.length > 0) {
          observer.observe(posts.last()[0]);
        }
      }
    }

    scrollPosts(direction) {
      const scrollAmount = this.scroller.width() * 0.8;
      const targetScroll = direction === 'next'
          ? this.scroller.scrollLeft() + scrollAmount
          : this.scroller.scrollLeft() - scrollAmount;

      this.scroller.animate({
        scrollLeft: targetScroll
      }, 300);
    }

    updateNavigationState() {
      const nav = $('<div class="bluesky-nav-buttons"></div>');
      const prevButton = $('<button class="bluesky-nav-button bluesky-prev"><</button>');
      const nextButton = $('<button class="bluesky-nav-button bluesky-next">></button>');

      nav.append(prevButton)
      nav.append(nextButton);

      if (prevButton.length && nextButton.length) {
        prevButton.toggle(this.scroller.scrollLeft() > 0);
        nextButton.toggle((this.scroller.scrollLeft() + this.scroller.width()) < this.scroller[0].scrollWidth);
      }
    }
  }

  $(document).ready(() => {
    $('.bluesky-feed-container').each((_, container) => {
      new BlueSkyFeed(container);
    });
  });
})(jQuery);