<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collapses long tag lists.
 *
 * CSS hides the tags beyond the limit once the container carries the
 * `is-collapsed` class, and a small footer script adds the "more tags" toggle
 * that switches the class. The plugin prints no tag names itself: the script
 * reads the existing links from the DOM, so a tag name never reaches the
 * markup produced here.
 */
final class LPT_Tags {

	/**
	 * Cached answer for the current request: does the tag block render?
	 *
	 * @var bool|null
	 */
	private ?bool $has_tag_block = null;

	/**
	 * Hooks the styles and the footer script.
	 */
	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'wp_footer', array( $this, 'print_js' ) );
	}

	/**
	 * Whether the limiting runs in the current request.
	 *
	 * Only front-end HTML documents get the styles and the footer script. The
	 * hooks used here do not fire in the admin, in a feed, in a REST response,
	 * in cron or in admin-ajax; the checks stay because a theme may print
	 * wp_footer() in an unusual context, and an inline script is not valid
	 * inside a feed or an AMP document.
	 *
	 * The lpt_enabled filter is the documented way to turn the behaviour off
	 * without editing the plugin.
	 *
	 * @return bool
	 */
	private function should_limit(): bool {
		if ( ! apply_filters( 'lpt_enabled', true ) ) {
			return false;
		}

		if ( is_admin() || is_feed() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		// AMP forbids custom inline scripts, so the toggle cannot work there.
		if ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether the current page actually renders the tag block.
	 *
	 * The assets are useless without the `.post-meta__tags` markup: the owner
	 * rule keeps them off every other page. The theme prints that block in a
	 * singular post that has tags and on tag archives (checked against the
	 * live theme markup); the front page and category archives also list
	 * posts, but they stay without the plugin assets on purpose.
	 *
	 * @return bool
	 */
	private function should_output(): bool {
		if ( ! $this->should_limit() ) {
			return false;
		}

		if ( null === $this->has_tag_block ) {
			$this->has_tag_block = $this->detect_tag_block();
		}

		return $this->has_tag_block;
	}

	/**
	 * Detects the tag block for the current request.
	 *
	 * @return bool
	 */
	private function detect_tag_block(): bool {
		if ( is_singular() ) {
			$post_id = (int) get_queried_object_id();
			if ( $post_id <= 0 ) {
				return false;
			}

			return (bool) get_the_tags( $post_id );
		}

		// Tag archives render the same footer block for their tag.
		if ( is_tag() ) {
			return true;
		}

		// A list of posts — the blog page, an archive, a search — carries the same
		// cards with the same tag block, so it needs the styles and the toggle
		// too. The loop decides: a page whose posts have no tags gets nothing.
		if ( is_home() || is_archive() || is_search() || is_front_page() ) {
			global $wp_query;

			foreach ( (array) ( $wp_query->posts ?? array() ) as $post ) {
				if ( isset( $post->ID ) && get_the_tags( (int) $post->ID ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Appends a suffix to every part of a comma-separated selector.
	 *
	 * A container selector may be a list (".a, .b"); plain concatenation
	 * would apply the suffix to the last part only and the rules for the
	 * other containers would silently disappear.
	 *
	 * @param string $selector Container selector.
	 * @param string $suffix   Text appended to each part, e.g. ' a'.
	 * @return string
	 */
	private static function with_suffix( string $selector, string $suffix ): string {
		$expanded = array();

		foreach ( explode( ',', $selector ) as $part ) {
			$part = trim( $part );
			if ( '' !== $part ) {
				$expanded[] = $part . $suffix;
			}
		}

		return implode( ',' . "\n\t\t\t", $expanded );
	}

	/**
	 * Registers the inline CSS that hides the extra tags once the list is
	 * marked as collapsed.
	 *
	 * The same container selector feeds the CSS and the footer script. The
	 * extra links are hidden through the `lpt-hidden` class the script puts on
	 * them, so the rules do not depend on how the theme nests the tag links.
	 */
	public function enqueue_styles(): void {
		if ( ! $this->should_output() ) {
			return;
		}

		wp_register_style( LPT_Config::STYLE_HANDLE, false, array(), LPT_VERSION );
		wp_enqueue_style( LPT_Config::STYLE_HANDLE );

		$selector = LPT_Config::container_selector();
		$images   = self::with_suffix( $selector, ' img' );
		$links    = self::with_suffix( $selector, ' a' );
		$hidden   = self::with_suffix( $selector, '.is-collapsed .lpt-hidden' );
		$toggle   = self::with_suffix( $selector, ' .tag-toggle' );
		$hover    = self::with_suffix( $selector, ' .tag-toggle:hover' );
		$focus    = self::with_suffix( $selector, ' .tag-toggle:focus-visible' );

		$css = '
			/* Reset theme clamps and restore the normal text flow. */
			' . $selector . ',
			' . $images . ' {
				-webkit-line-clamp: unset !important;
				-webkit-box-orient: unset !important;
				overflow: visible !important;
				max-height: none !important;
				text-overflow: clip !important;
				line-height: inherit;
			}

			' . $links . ' {
				display: inline-block;
				margin: 0 8px 0 0;
				color: inherit;
				vertical-align: baseline;
			}

			/* The script marks the extra items with .lpt-hidden and puts
			   is-collapsed on the container; together they hide the extra
			   tags. An item is a tag link or the wrapper that holds it.
			   Hidden items take no space, so the button sits right after the
			   last visible tag. */
			' . $hidden . ' {
				display: none;
			}

			/* The toggle button stays inline and continues the list. */
			' . $toggle . ' {
				display: inline-block;
				background: none;
				border: 0;
				padding: 0;
				margin: 0 0 4px 0;
				font: inherit;
				color: inherit;
				opacity: 0.65;
				cursor: pointer;
				text-decoration: underline;
				text-decoration-style: dashed;
				text-underline-offset: 3px;
				vertical-align: baseline;
				transition: opacity .15s ease;
			}

			' . $hover . ',
			' . $focus . ' {
				opacity: 1;
				text-decoration-style: solid;
				outline: none;
			}
		';

		wp_add_inline_style( LPT_Config::STYLE_HANDLE, $css );
	}

	/**
	 * Prints the footer script that collapses the list and builds the toggle
	 * button.
	 *
	 * The script never receives tag names from PHP: it reads the existing
	 * links from the DOM and writes the counter with textContent, so a tag
	 * name cannot inject markup. The configuration below is numbers and
	 * translated strings only, encoded as JSON; JSON_HEX_TAG keeps a
	 * `</script>` sequence out of the inline block even if a translation or
	 * the filters return one.
	 */
	public function print_js(): void {
		if ( ! $this->should_output() ) {
			return;
		}

		$config = array(
			'max'             => LPT_Config::max_tags(),
			'minHidden'       => LPT_Config::min_hidden(),
			'selector'        => LPT_Config::container_selector(),
			'defaultSelector' => LPT_Config::CONTAINER_SELECTOR,
			// translators: %d: number of hidden tags, %s: the Russian plural form of "tag".
			'textMore'        => __( 'Ещё %d %s', 'limit-post-tags' ),
			'textLess'        => __( 'Свернуть', 'limit-post-tags' ),
			'plural'          => array(
				'one'  => __( 'метка', 'limit-post-tags' ),
				'few'  => __( 'метки', 'limit-post-tags' ),
				'many' => __( 'меток', 'limit-post-tags' ),
			),
		);
		?>
		<script>
		(function () {
			var CONFIG = <?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); ?>;
			var MAX = CONFIG.max;
			var MIN_HIDDEN = CONFIG.minHidden;
			var TEXT_MORE = CONFIG.textMore;
			var TEXT_LESS = CONFIG.textLess;

			/**
			 * Tag containers for the configured selector.
			 *
			 * The selector comes from a filter; if it is not a valid CSS
			 * selector, querySelectorAll() throws and the default selector is
			 * used so the rest of the page keeps working.
			 *
			 * @return {NodeList} Matching containers.
			 */
			function findContainers() {
				try {
					return document.querySelectorAll(CONFIG.selector);
				} catch (e) {
					return document.querySelectorAll(CONFIG.defaultSelector);
				}
			}

			/**
			 * Plural form of the Russian word for "tag" (CLDR rules).
			 *
			 * The three forms come from the PHP config, so they are
			 * translation strings rather than hard-coded JS literals.
			 *
			 * @param {number} n Item count.
			 * @return {string} Word in the matching form.
			 */
			function pluralizeTags(n) {
				var mod10  = n % 10;
				var mod100 = n % 100;

				if (mod10 === 1 && mod100 !== 11) {
					return CONFIG.plural.one;
				}
				if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) {
					return CONFIG.plural.few;
				}
				return CONFIG.plural.many;
			}

			/**
			 * Collapses one tag container and adds its toggle.
			 *
			 * @param {Element} c A tag list container.
			 */
			function initContainer(c) {
				// Guard against a second run (AJAX, infinite scroll).
				if (c.getAttribute('data-lpt') === '1') {
					return;
				}
				c.setAttribute('data-lpt', '1');

				// One item is a direct <a>, or a direct wrapper that contains
				// exactly one <a> (span, li, ...). Wrappers with zero or more
				// than one link are not tag items and stay untouched.
				var items = [];
				for (var j = 0; j < c.children.length; j++) {
					var child = c.children[j];

					if (child.tagName === 'A') {
						items.push(child);
						continue;
					}

					if (child.querySelectorAll('a').length === 1) {
						items.push(child);
					}
				}

				// Everything fits — nothing to do.
				if (items.length <= MAX) {
					return;
				}

				var hidden = items.length - MAX;

				// Too few hidden tags for a useful button.
				if (hidden < MIN_HIDDEN) {
					return;
				}

				// aria-controls needs an id; reuse the theme one if present.
				if (!c.id) {
					c.id = 'lpt-tags-' + Math.random().toString(36).slice(2, 9);
				}

				// The extra items carry .lpt-hidden and the container carries
				// is-collapsed; the CSS hides one through the other, so the
				// markup of the theme no longer matters. On a wrapper the class
				// goes on the wrapper, otherwise the wrapper would keep taking
				// space while its link is hidden.
				for (var k = MAX; k < items.length; k++) {
					items[k].classList.add('lpt-hidden');
				}
				c.className += ' is-collapsed';

				var toggle = document.createElement('button');
				toggle.className = 'tag-toggle';
				toggle.type = 'button';
				toggle.setAttribute('aria-controls', c.id);
				toggle.setAttribute('aria-expanded', 'false');
				toggle.textContent = TEXT_MORE
					.replace('%d', hidden)
					.replace('%s', pluralizeTags(hidden));

				toggle.addEventListener('click', function () {
					var expanded = c.className.indexOf('is-expanded') !== -1;

					if (expanded) {
						// Collapse.
						c.className = c.className
							.replace(/\s*is-expanded/, '')
							.replace(/\s*is-collapsed/, '') + ' is-collapsed';

						toggle.textContent = TEXT_MORE
							.replace('%d', hidden)
							.replace('%s', pluralizeTags(hidden));
						toggle.setAttribute('aria-expanded', 'false');
					} else {
						// Expand.
						c.className = c.className
							.replace(/\s*is-collapsed/, '')
							.replace(/\s*is-expanded/, '') + ' is-expanded';

						toggle.textContent = TEXT_LESS;
						toggle.setAttribute('aria-expanded', 'true');
					}
				});

				// Appended last: the hidden tags take no space, so the button
				// lands right after the last visible tag.
				c.appendChild(toggle);
			}

			/**
			 * Initialises every tag container on the page.
			 */
			function apply() {
				var containers = findContainers();
				for (var i = 0; i < containers.length; i++) {
					initContainer(containers[i]);
				}
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', apply);
			} else {
				apply();
			}
		})();
		</script>
		<?php
	}
}
