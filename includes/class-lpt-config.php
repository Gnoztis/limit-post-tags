<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin configuration shared by the feature classes.
 */
final class LPT_Config {

	/* ============================================================
	 *  Tag limit
	 * ============================================================ */

	/** Maximum number of tags shown before the list is collapsed. */
	public const MAX_TAGS = 5;

	/** A list is collapsed only when at least this many tags are hidden. */
	public const MIN_HIDDEN_TO_COLLAPSE = 2;

	/** Upper bound for the filtered limit — keeps the CSS selector sane. */
	public const MAX_TAGS_CEILING = 50;

	/** Upper bound for the filtered hidden-tags threshold. */
	public const MIN_HIDDEN_CEILING = 50;

	/** Handle of the inline styles. */
	public const STYLE_HANDLE = 'lpt-limit-post-tags';

	/** Default selector of the tag list container. */
	public const CONTAINER_SELECTOR = '.post-meta__tags';

	/**
	 * Maximum number of tags shown, after the lpt_max_tags filter.
	 *
	 * The filter lets a site tune the limit without editing the plugin. The
	 * value is cast to int and clamped, so 0, a negative number or a
	 * non-numeric string cannot produce broken CSS or a toggle that never
	 * appears.
	 *
	 * @return int Between 1 and MAX_TAGS_CEILING.
	 */
	public static function max_tags(): int {
		$max = (int) apply_filters( 'lpt_max_tags', self::MAX_TAGS );

		return max( 1, min( self::MAX_TAGS_CEILING, $max ) );
	}

	/**
	 * Number of hidden tags required for the toggle, after the
	 * lpt_min_hidden_to_collapse filter.
	 *
	 * @return int Between 1 and MIN_HIDDEN_CEILING.
	 */
	public static function min_hidden(): int {
		$min = (int) apply_filters( 'lpt_min_hidden_to_collapse', self::MIN_HIDDEN_TO_COLLAPSE );

		return max( 1, min( self::MIN_HIDDEN_CEILING, $min ) );
	}

	/**
	 * Selector of the tag list container, after the lpt_container_selector
	 * filter.
	 *
	 * The value is printed into an inline <style> block and passed to
	 * querySelectorAll(), so the characters that could break out of either
	 * context are rejected and the default selector is used instead. Child
	 * combinators and attribute selectors are allowed, because a container
	 * selector may legitimately contain them.
	 *
	 * @return string A usable CSS selector.
	 */
	public static function container_selector(): string {
		$selector = trim( (string) apply_filters( 'lpt_container_selector', self::CONTAINER_SELECTOR ) );

		if ( '' === $selector || false !== strpbrk( $selector, '{}<;\\/' ) || preg_match( '/[\r\n]/', $selector ) ) {
			return self::CONTAINER_SELECTOR;
		}

		return $selector;
	}

	/* ============================================================
	 *  GitHub updates
	 * ============================================================ */

	/** GitHub repository the updates come from. */
	public const REPO = 'Gnoztis/limit-post-tags';

	/** Plugin slug — also the archive directory name on GitHub. */
	public const SLUG = 'limit-post-tags';

	/** Plugin basename relative to wp-content/plugins. */
	public const BASENAME = 'limit-post-tags/limit-post-tags.php';

	/** Transient holding the result of the last successful update check. */
	public const UPDATE_TRANSIENT = 'lpt_github_release';

	/** Transient holding the failure marker of the last update check. */
	public const UPDATE_ERROR_TRANSIENT = 'lpt_github_release_error';

	/** Successful update check is cached for six hours. */
	public const UPDATE_CACHE_TTL = 21600;

	/** A failed update check is retried after fifteen minutes. */
	public const UPDATE_ERROR_TTL = 900;
}
