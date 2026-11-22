<?php
/**
 * Plugin Name: Limit Post Tags
 * Description: Ограничивает вывод меток до 5 штук и добавляет кнопку «Ещё N меток» как продолжение списка. Скрытие лишних меток через CSS-класс, без обрезки по высоте — кнопка всегда видна.
 * Version:     1.6.3
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Защита от повторного объявления класса.
if ( ! class_exists( 'WPLPT_Limit_Post_Tags' ) ) {

	final class WPLPT_Limit_Post_Tags {

		/** Максимальное количество меток, отображаемых по умолчанию */
		const MAX_TAGS = 5;

		/** Если скрытых меток меньше этого числа — кнопка не создаётся */
		const MIN_HIDDEN_TO_COLLAPSE = 2;

		/** Хэндл для inline-стилей */
		const STYLE_HANDLE = 'wplpt-limit-post-tags';

		public function __construct() {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
			add_action( 'wp_footer',          array( $this, 'print_js' ) );
		}

		/**
		 * Нужно ли применять ограничение на текущем экране.
		 * В админке — не трогаем.
		 */
		private function should_limit() {
			return ! is_admin();
		}

		public function enqueue_styles() {
			if ( ! $this->should_limit() ) {
				return;
			}

			wp_register_style( self::STYLE_HANDLE, false, array(), '1.6.3' );
			wp_enqueue_style( self::STYLE_HANDLE );

			$max = (int) self::MAX_TAGS;

			$css = '
				/* Сбрасываем ограничения темы и возвращаем обычный поток текста */
				.post-meta__tags,
				.post-meta__tags img {
					display: block !important;
					-webkit-line-clamp: unset !important;
					-webkit-box-orient: unset !important;
					overflow: visible !important;
					max-height: none !important;
					text-overflow: clip !important;
					line-height: 1.6;
				}

				.post-meta__tags a {
					display: inline-block;
					margin: 0 6px 4px 0;
					color: inherit;
					vertical-align: baseline;
				}

				/* Скрываем лишние метки — они не занимают места,
				   поэтому кнопка встаёт сразу после последней видимой метки */
				.post-meta__tags.is-collapsed > a:nth-of-type(n + ' . ( $max + 1 ) . ') {
					display: none;
				}

				/* Кнопка «Ещё N меток» — inline, продолжает список */
				.post-meta__tags .tag-toggle {
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

				.post-meta__tags .tag-toggle:hover,
				.post-meta__tags .tag-toggle:focus-visible {
					opacity: 1;
					text-decoration-style: solid;
					outline: none;
				}
			';

			wp_add_inline_style( self::STYLE_HANDLE, $css );
		}

		public function print_js() {
			if ( ! $this->should_limit() ) {
				return;
			}
			?>
			<script>
			(function () {
				var MAX = <?php echo (int) self::MAX_TAGS; ?>;
				var MIN_HIDDEN = <?php echo (int) self::MIN_HIDDEN_TO_COLLAPSE; ?>;
				var TEXT_MORE = 'Ещё %d %s';
				var TEXT_LESS = 'Свернуть';

				/**
				 * Склонение слова «метка» по числу (правила CLDR для русского).
				 */
				function pluralizeTags(n) {
					var mod10  = n % 10;
					var mod100 = n % 100;

					if (mod10 === 1 && mod100 !== 11) {
						return 'метка';
					}
					if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) {
						return 'метки';
					}
					return 'меток';
				}

				function initContainer(c) {
					// Защита от повторной инициализации
					if (c.getAttribute('data-wplpt') === '1') {
						return;
					}
					c.setAttribute('data-wplpt', '1');

					// Собираем только прямые дочерние ссылки-метки
					var links = [];
					for (var j = 0; j < c.children.length; j++) {
						if (c.children[j].tagName === 'A') {
							links.push(c.children[j]);
						}
					}

					// Если все метки помещаются — ничего не делаем
					if (links.length <= MAX) {
						return;
					}

					var hidden = links.length - MAX;

					// Если скрытых меток слишком мало — кнопку не создаём
					if (hidden < MIN_HIDDEN) {
						return;
					}

					// Уникальный id контейнера — нужен для aria-controls
					if (!c.id) {
						c.id = 'wplpt-tags-' + Math.random().toString(36).slice(2, 9);
					}

					// Скрытие управляется ТОЛЬКО классом.
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
							// Сворачиваем
							c.className = c.className
								.replace(/\s*is-expanded/, '')
								.replace(/\s*is-collapsed/, '') + ' is-collapsed';

							toggle.textContent = TEXT_MORE
								.replace('%d', hidden)
								.replace('%s', pluralizeTags(hidden));
							toggle.setAttribute('aria-expanded', 'false');
						} else {
							// Раскрываем
							c.className = c.className
								.replace(/\s*is-collapsed/, '')
								.replace(/\s*is-expanded/, '') + ' is-expanded';

							toggle.textContent = TEXT_LESS;
							toggle.setAttribute('aria-expanded', 'true');
						}
					});

					// Кнопка добавляется в конец контейнера; скрытые метки
					// не занимают места, поэтому она встаёт сразу после видимых.
					c.appendChild(toggle);
				}

				function apply() {
					var containers = document.querySelectorAll('.post-meta__tags');
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

	new WPLPT_Limit_Post_Tags();
}