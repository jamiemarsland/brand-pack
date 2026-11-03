<?php
/**
 * Plugin Name: Brand Pack
 * Description: Turns your site into a brand pack that AI agents read before they write, design or review anything for you. Voice, visual identity, rules and facts in one file, kept in sync with your site.
 * Version: 0.1.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: James Marsland
 * License: GPL-2.0-or-later
 * Text Domain: brand-pack
 */

defined( 'ABSPATH' ) || exit;

final class Brand_Pack {

	const VERSION     = '0.1.0';
	const OPTION      = 'brand_pack';
	const CACHE       = 'brand_pack_cache';
	const DIRTY       = 'brand_pack_dirty';
	const HISTORY     = 'brand_pack_history';
	const MAX_HISTORY = 20;
	const SAMPLE_MAX  = 5;
	const SAMPLE_WORDS = 180;

	/**
	 * What only a person can tell an agent. `list` fields take one item per line.
	 */
	const FIELDS = array(
		'positioning' => array(
			'label' => 'What you do',
			'type'  => 'textarea',
			'help'  => 'One or two sentences: what you offer, and what makes it different.',
		),
		'audience'    => array(
			'label' => 'Who you write for',
			'type'  => 'textarea',
			'help'  => 'Who reads your site, what they already know, what they want.',
		),
		'voice'       => array(
			'label' => 'Voice',
			'type'  => 'list',
			'help'  => 'Three to five words or short phrases, one per line. E.g. "Warm, never gushing".',
		),
		'say'         => array(
			'label' => 'Words we use',
			'type'  => 'list',
			'help'  => 'Product names, spellings and phrases that are "ours". One per line.',
		),
		'avoid'       => array(
			'label' => 'Words we never use',
			'type'  => 'list',
			'help'  => 'One per line. Agents are told never to use these, and the check tool flags them.',
		),
		'claims'      => array(
			'label' => 'Claims we never make',
			'type'  => 'list',
			'help'  => 'E.g. "Never promise results by a date". One per line.',
		),
		'competitors' => array(
			'label' => 'Never mention',
			'type'  => 'list',
			'help'  => 'Competitors or names agents must leave out. One per line.',
		),
		'disclaimers' => array(
			'label' => 'Required disclaimers',
			'type'  => 'textarea',
			'help'  => 'Legal or affiliate wording that must appear where it applies.',
		),
		'ctas'        => array(
			'label' => 'Calls to action',
			'type'  => 'list',
			'help'  => 'One per line, as "Button text | https://link".',
		),
		'notes'       => array(
			'label' => 'Anything else',
			'type'  => 'textarea',
			'help'  => 'Anything an agent should know that does not fit above.',
		),
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrites' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'ability_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'abilities' ) );

		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_brand_pack_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_brand_pack_token', array( __CLASS__, 'regenerate_token' ) );
		add_action( 'admin_post_brand_pack_skill', array( __CLASS__, 'download_skill' ) );

		// Anything that changes what the pack says marks it stale; it rebuilds on next read.
		add_action( 'save_post', array( __CLASS__, 'post_changed' ) );
		add_action( 'deleted_post', array( __CLASS__, 'mark_dirty' ) );
		add_action( 'switch_theme', array( __CLASS__, 'mark_dirty' ) );
		add_action( 'customize_save_after', array( __CLASS__, 'mark_dirty' ) );
		add_action( 'wp_update_nav_menu', array( __CLASS__, 'mark_dirty' ) );
		add_action( 'edited_term', array( __CLASS__, 'mark_dirty' ) );
		foreach ( array( 'blogname', 'blogdescription', 'site_icon', 'site_logo', 'theme_mods_' . get_stylesheet() ) as $option ) {
			add_action( 'update_option_' . $option, array( __CLASS__, 'mark_dirty' ) );
		}

		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
	}

	public static function activate() {
		self::options();
		self::rewrites();
		flush_rewrite_rules();
	}

	/* ------------------------------------------------------------------ *
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function options() {
		$saved = get_option( self::OPTION );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$defaults = array(
			'public'   => false,
			'token'    => '',
			'samples'  => array(),
			'examples' => array(),
		);
		foreach ( self::FIELDS as $key => $field ) {
			$defaults[ $key ] = 'list' === $field['type'] ? array() : '';
		}
		$options = array_merge( $defaults, $saved );

		if ( '' === $options['token'] ) {
			$options['token'] = wp_generate_password( 32, false );
			update_option( self::OPTION, $options, false );
		}
		return $options;
	}

	public static function post_changed( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::mark_dirty();
	}

	public static function mark_dirty() {
		if ( ! get_option( self::DIRTY ) ) {
			update_option( self::DIRTY, 1, false );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Building the pack
	 * ------------------------------------------------------------------ */

	/**
	 * The current pack, rebuilt only when something on the site changed.
	 */
	public static function get_pack( $force = false ) {
		$cache = get_option( self::CACHE );
		if ( ! $force && is_array( $cache ) && ! get_option( self::DIRTY ) && self::VERSION === ( $cache['plugin'] ?? '' ) ) {
			return $cache['pack'];
		}

		$pack     = self::build();
		$markdown = self::to_markdown( $pack );
		$hash     = md5( $markdown );

		update_option(
			self::CACHE,
			array(
				'plugin'   => self::VERSION,
				'pack'     => $pack,
				'markdown' => $markdown,
				'hash'     => $hash,
			),
			false
		);
		delete_option( self::DIRTY );
		self::record_history( $hash, $markdown );

		return $pack;
	}

	public static function get_markdown() {
		self::get_pack();
		$cache = get_option( self::CACHE );
		return $cache['markdown'];
	}

	private static function record_history( $hash, $markdown ) {
		$history = get_option( self::HISTORY, array() );
		if ( $history && $history[0]['hash'] === $hash ) {
			return;
		}
		array_unshift(
			$history,
			array(
				'time'     => time(),
				'hash'     => $hash,
				'markdown' => $markdown,
			)
		);
		update_option( self::HISTORY, array_slice( $history, 0, self::MAX_HISTORY ), false );
	}

	public static function build() {
		$o = self::options();

		$pack = array(
			'schema'      => 'brand-pack/1',
			'site'        => array(
				'name'     => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'tagline'  => html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
				'url'      => home_url( '/' ),
				'language' => get_bloginfo( 'language' ),
			),
			'positioning' => $o['positioning'],
			'audience'    => $o['audience'],
			'about'       => self::about(),
			'voice'       => array(
				'descriptors' => $o['voice'],
				'say'         => $o['say'],
				'avoid'       => $o['avoid'],
			),
			'rules'       => array(
				'never_claim'   => $o['claims'],
				'never_mention' => $o['competitors'],
				'disclaimers'   => $o['disclaimers'],
			),
			'ctas'        => self::ctas( $o['ctas'] ),
			'examples'    => $o['examples'],
			'visual'      => self::visual(),
			'topics'      => self::topics(),
			'navigation'  => self::navigation(),
			'products'    => self::products(),
			'samples'     => self::samples( $o['samples'] ),
			'notes'       => $o['notes'],
		);

		return apply_filters( 'brand_pack_data', $pack );
	}

	private static function about() {
		$page = get_page_by_path( 'about' );
		if ( ! $page ) {
			$found = get_posts(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'title'       => 'About',
					'numberposts' => 1,
				)
			);
			$page  = $found ? $found[0] : null;
		}
		if ( ! $page || 'publish' !== $page->post_status ) {
			return null;
		}
		return array(
			'title' => get_the_title( $page ),
			'url'   => get_permalink( $page ),
			'text'  => self::plain_text( $page->post_content, 300 ),
		);
	}

	private static function visual() {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( ! $logo_id ) {
			$logo_id = (int) get_option( 'site_logo' );
		}

		$visual = array(
			'logo'    => $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : null,
			'logo_id' => $logo_id ?: null,
			'icon'    => get_site_icon_url( 512 ) ?: null,
			'colors'  => array(),
			'fonts'   => array(),
			'roles'   => array(),
			'theme'   => wp_get_theme()->get( 'Name' ),
		);

		if ( ! function_exists( 'wp_get_global_settings' ) || ! wp_theme_has_theme_json() ) {
			$bg = get_theme_mod( 'background_color' );
			if ( $bg ) {
				$visual['colors'][]            = array( 'name' => 'Background', 'slug' => 'background', 'value' => '#' . ltrim( $bg, '#' ) );
				$visual['roles']['background'] = '#' . ltrim( $bg, '#' );
			}
			return $visual;
		}

		// Custom (user-edited) entries win over the theme's own.
		$color_map = array();
		$palette   = wp_get_global_settings( array( 'color', 'palette' ) );
		foreach ( array( 'theme', 'custom' ) as $origin ) {
			foreach ( (array) ( $palette[ $origin ] ?? array() ) as $color ) {
				// Agents can only use literal colours, not color-mix() or var() expressions.
				if ( empty( $color['slug'] ) || empty( $color['color'] ) || str_contains( $color['color'], '(' ) && ! preg_match( '/^(rgb|hsl)a?\(/i', $color['color'] ) ) {
					continue;
				}
				$color_map[ $color['slug'] ] = array(
					'name'  => $color['name'] ?? $color['slug'],
					'slug'  => $color['slug'],
					'value' => $color['color'],
				);
			}
		}

		$font_map = array();
		$families = wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
		foreach ( array( 'theme', 'custom' ) as $origin ) {
			foreach ( (array) ( $families[ $origin ] ?? array() ) as $font ) {
				if ( empty( $font['slug'] ) ) {
					continue;
				}
				$font_map[ $font['slug'] ] = array(
					'name'  => $font['name'] ?? $font['slug'],
					'slug'  => $font['slug'],
					'stack' => $font['fontFamily'] ?? '',
				);
			}
		}

		$styles  = wp_get_global_styles();
		$colors  = array_map( fn( $c ) => $c['value'], $color_map );
		$fonts   = array_map( fn( $f ) => $f['name'], $font_map );
		$element = fn( $name, ...$path ) => _wp_array_get( $styles, array_merge( array( 'elements', $name ), $path ) );

		$roles = array(
			'background'       => self::resolve( $styles['color']['background'] ?? null, $colors ),
			'text'             => self::resolve( $styles['color']['text'] ?? null, $colors ),
			'link'             => self::resolve( $element( 'link', 'color', 'text' ), $colors ),
			'heading'          => self::resolve( $element( 'heading', 'color', 'text' ), $colors ),
			'button'           => self::resolve( $element( 'button', 'color', 'background' ), $colors ),
			'button_text'      => self::resolve( $element( 'button', 'color', 'text' ), $colors ),
			'body_font'        => self::resolve( $styles['typography']['fontFamily'] ?? null, $fonts ),
			'heading_font'     => self::resolve( $element( 'heading', 'typography', 'fontFamily' ), $fonts ),
			'content_width'    => wp_get_global_settings( array( 'layout', 'contentSize' ) ),
			'wide_width'       => wp_get_global_settings( array( 'layout', 'wideSize' ) ),
		);

		$visual['colors'] = array_values( $color_map );
		$visual['fonts']  = array_values( $font_map );
		$visual['roles']  = array_filter( $roles, fn( $v ) => is_string( $v ) && '' !== $v );

		return $visual;
	}

	/**
	 * Turns a preset reference like `var:preset|color|accent` or
	 * `var(--wp--preset--font-family--inter)` into its real value.
	 */
	private static function resolve( $value, $map ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( preg_match( '/(?:var:preset\|[a-z-]+\||var\(--wp--preset--(?:color|font-family)--)([a-z0-9-]+)/', $value, $m ) ) {
			return $map[ $m[1] ] ?? $m[1];
		}
		return $value;
	}

	private static function topics() {
		$topics = array();
		$terms  = get_terms(
			array(
				'taxonomy'   => array( 'category', 'post_tag' ),
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 20,
				'hide_empty' => true,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $topics;
		}
		foreach ( $terms as $term ) {
			if ( 'uncategorized' === $term->slug ) {
				continue;
			}
			$topics[] = array(
				'name'  => html_entity_decode( $term->name, ENT_QUOTES ),
				'type'  => 'category' === $term->taxonomy ? 'category' : 'tag',
				'posts' => (int) $term->count,
				'url'   => get_term_link( $term ),
			);
		}
		return $topics;
	}

	/**
	 * Top-level navigation: a classic menu, else the block navigation, else top-level pages.
	 */
	private static function navigation() {
		$items = array();

		foreach ( get_nav_menu_locations() as $menu_id ) {
			foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
				if ( ! (int) $item->menu_item_parent ) {
					$items[] = array( 'label' => $item->title, 'url' => $item->url );
				}
			}
			if ( $items ) {
				return $items;
			}
		}

		$nav = get_posts(
			array(
				'post_type'   => 'wp_navigation',
				'post_status' => 'publish',
				'numberposts' => 1,
			)
		);
		if ( $nav ) {
			foreach ( parse_blocks( $nav[0]->post_content ) as $block ) {
				if ( in_array( $block['blockName'], array( 'core/navigation-link', 'core/navigation-submenu' ), true ) && ! empty( $block['attrs']['label'] ) ) {
					$items[] = array(
						'label' => wp_strip_all_tags( $block['attrs']['label'] ),
						'url'   => $block['attrs']['url'] ?? '',
					);
				}
			}
			if ( $items ) {
				return $items;
			}
		}

		foreach ( get_pages( array( 'parent' => 0, 'sort_column' => 'menu_order', 'number' => 8 ) ) as $page ) {
			$items[] = array( 'label' => get_the_title( $page ), 'url' => get_permalink( $page ) );
		}
		return $items;
	}

	private static function products() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$products = array();
		foreach ( wc_get_products( array( 'limit' => 20, 'status' => 'publish', 'orderby' => 'popularity' ) ) as $product ) {
			$products[] = array(
				'name'        => $product->get_name(),
				'price'       => html_entity_decode( wp_strip_all_tags( wc_price( $product->get_price() ) ), ENT_QUOTES ),
				'url'         => $product->get_permalink(),
				'description' => self::plain_text( $product->get_short_description() ?: $product->get_description(), 40 ),
			);
		}
		return $products;
	}

	/**
	 * Writing samples: the posts picked on the settings screen, else the latest posts.
	 */
	public static function samples( $pinned = array() ) {
		$args = array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'numberposts' => self::SAMPLE_MAX,
		);
		if ( $pinned ) {
			$args['post__in'] = array_map( 'intval', $pinned );
			$args['orderby']  = 'post__in';
		}

		$samples = array();
		foreach ( get_posts( $args ) as $post ) {
			$text = self::plain_text( $post->post_content, self::SAMPLE_WORDS );
			// Skip empty posts and WordPress's stock first post, which is not the site's voice.
			if ( '' === $text || ( ! $pinned && str_starts_with( $text, 'Welcome to WordPress.' ) ) ) {
				continue;
			}
			$samples[] = array(
				'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES ),
				'url'   => get_permalink( $post ),
				'date'  => get_the_date( 'Y-m-d', $post ),
				'text'  => $text,
			);
		}
		return $samples;
	}

	private static function ctas( $lines ) {
		$ctas = array();
		foreach ( $lines as $line ) {
			$parts  = array_map( 'trim', explode( '|', $line, 2 ) );
			$ctas[] = array(
				'text' => $parts[0],
				'url'  => $parts[1] ?? '',
			);
		}
		return $ctas;
	}

	private static function plain_text( $html, $words ) {
		$text = wp_strip_all_tags( strip_shortcodes( $html ) );
		$text = trim( preg_replace( '/\s+/', ' ', html_entity_decode( $text, ENT_QUOTES ) ) );
		return wp_trim_words( $text, $words, '…' );
	}

	/* ------------------------------------------------------------------ *
	 * Markdown
	 * ------------------------------------------------------------------ */

	public static function to_markdown( $p ) {
		$name = $p['site']['name'];
		$out  = array();
		$list = function ( $items ) use ( &$out ) {
			foreach ( $items as $item ) {
				$out[] = '- ' . $item;
			}
			$out[] = '';
		};
		$quote = fn( $text ) => '> ' . str_replace( "\n", "\n> ", trim( $text ) );

		$out[] = "# {$name} brand pack";
		$out[] = '';
		$out[] = "Read this before you write, design or review anything published as {$name}. The **Never** section holds hard rules. Everything else is guidance: when in doubt, match the writing samples at the end.";
		$out[] = '';
		$out[] = '- Website: ' . $p['site']['url'];
		if ( $p['site']['tagline'] ) {
			$out[] = '- Tagline: ' . $p['site']['tagline'];
		}
		$out[] = '- Language: ' . $p['site']['language'];
		$out[] = '';

		if ( $p['positioning'] || $p['about'] ) {
			$out[] = '## Who we are';
			$out[] = '';
			if ( $p['positioning'] ) {
				$out[] = trim( $p['positioning'] );
				$out[] = '';
			}
			if ( $p['about'] ) {
				$out[] = 'From our [' . $p['about']['title'] . '](' . $p['about']['url'] . ') page:';
				$out[] = '';
				$out[] = $quote( $p['about']['text'] );
				$out[] = '';
			}
		}

		if ( $p['audience'] ) {
			$out[] = '## Who we write for';
			$out[] = '';
			$out[] = trim( $p['audience'] );
			$out[] = '';
		}

		$v = $p['voice'];
		if ( $v['descriptors'] || $v['say'] || $v['avoid'] ) {
			$out[] = '## Voice';
			$out[] = '';
			if ( $v['descriptors'] ) {
				$list( $v['descriptors'] );
			}
			if ( $v['say'] ) {
				$out[] = 'Words and phrases that are ours: ' . implode( ', ', array_map( fn( $w ) => "\"{$w}\"", $v['say'] ) ) . '.';
				$out[] = '';
			}
		}

		$r = $p['rules'];
		if ( $v['avoid'] || $r['never_claim'] || $r['never_mention'] ) {
			$out[] = '## Never';
			$out[] = '';
			if ( $v['avoid'] ) {
				$out[] = '- Never use these words: ' . implode( ', ', array_map( fn( $w ) => "\"{$w}\"", $v['avoid'] ) ) . '.';
			}
			foreach ( $r['never_claim'] as $claim ) {
				$out[] = '- ' . $claim;
			}
			if ( $r['never_mention'] ) {
				$out[] = '- Never mention: ' . implode( ', ', $r['never_mention'] ) . '.';
			}
			$out[] = '';
		}

		if ( $r['disclaimers'] ) {
			$out[] = '## Required disclaimers';
			$out[] = '';
			$out[] = trim( $r['disclaimers'] );
			$out[] = '';
		}

		if ( $p['ctas'] ) {
			$out[] = '## Calls to action';
			$out[] = '';
			$list( array_map( fn( $c ) => $c['url'] ? "[{$c['text']}]({$c['url']})" : $c['text'], $p['ctas'] ) );
		}

		if ( $p['examples'] ) {
			$out[] = '## Examples';
			$out[] = '';
			foreach ( $p['examples'] as $i => $ex ) {
				$out[] = '### Example ' . ( $i + 1 );
				$out[] = '';
				if ( $ex['bad'] ) {
					$out[] = 'Not this:';
					$out[] = '';
					$out[] = $quote( $ex['bad'] );
					$out[] = '';
				}
				$out[] = 'This:';
				$out[] = '';
				$out[] = $quote( $ex['good'] );
				$out[] = '';
				if ( $ex['why'] ) {
					$out[] = 'Why: ' . trim( $ex['why'] );
					$out[] = '';
				}
			}
		}

		$vis = $p['visual'];
		if ( $vis['logo'] || $vis['icon'] || $vis['colors'] || $vis['fonts'] ) {
			$out[] = '## Visual identity';
			$out[] = '';
			if ( $vis['logo'] ) {
				$out[] = '- Logo: ' . $vis['logo'];
			}
			if ( $vis['icon'] ) {
				$out[] = '- Icon: ' . $vis['icon'];
			}
			$roles = $vis['roles'];
			foreach ( array( 'heading_font' => 'Heading font', 'body_font' => 'Body font', 'content_width' => 'Content width' ) as $key => $label ) {
				if ( ! empty( $roles[ $key ] ) ) {
					$out[] = "- {$label}: {$roles[ $key ]}";
				}
			}
			$out[] = '';

			if ( $vis['colors'] ) {
				$color_roles = array( 'background' => 'Background', 'text' => 'Text', 'heading' => 'Headings', 'link' => 'Links', 'button' => 'Buttons', 'button_text' => 'Button text' );
				$out[]       = '| Colour | Value | Used for |';
				$out[]       = '| --- | --- | --- |';
				foreach ( $vis['colors'] as $color ) {
					$used  = array_keys( array_filter( array_intersect_key( $roles, $color_roles ), fn( $val ) => strtolower( $val ) === strtolower( $color['value'] ) ) );
					$out[] = '| ' . $color['name'] . ' | `' . $color['value'] . '` | ' . implode( ', ', array_map( fn( $k ) => $color_roles[ $k ], $used ) ) . ' |';
				}
				$out[] = '';
			}

			if ( $vis['fonts'] ) {
				$out[] = 'Font families: ' . implode( '; ', array_map( fn( $f ) => $f['name'] . ( $f['stack'] ? " (`{$f['stack']}`)" : '' ), $vis['fonts'] ) ) . '.';
				$out[] = '';
			}
		}

		if ( $p['topics'] ) {
			$out[] = '## Topics we cover';
			$out[] = '';
			$list( array_map( fn( $t ) => "{$t['name']} ({$t['posts']} " . ( 1 === $t['posts'] ? 'post' : 'posts' ) . ')', $p['topics'] ) );
		}

		if ( $p['navigation'] ) {
			$out[] = '## Site sections';
			$out[] = '';
			$list( array_map( fn( $n ) => $n['url'] ? "[{$n['label']}]({$n['url']})" : $n['label'], $p['navigation'] ) );
		}

		if ( $p['products'] ) {
			$out[] = '## Products';
			$out[] = '';
			$list( array_map( fn( $pr ) => "[{$pr['name']}]({$pr['url']}): {$pr['price']}" . ( $pr['description'] ? " — {$pr['description']}" : '' ), $p['products'] ) );
		}

		if ( $p['notes'] ) {
			$out[] = '## Notes';
			$out[] = '';
			$out[] = trim( $p['notes'] );
			$out[] = '';
		}

		if ( $p['samples'] ) {
			$out[] = '## Writing samples';
			$out[] = '';
			$out[] = 'Openings of real posts. Match their sentence length, person, formality and rhythm.';
			$out[] = '';
			foreach ( $p['samples'] as $s ) {
				$out[] = "### [{$s['title']}]({$s['url']})";
				$out[] = '';
				$out[] = $quote( $s['text'] );
				$out[] = '';
			}
		}

		return implode( "\n", $out );
	}

	/* ------------------------------------------------------------------ *
	 * Public endpoints: /brand.md, /.well-known/brand.json, REST
	 * ------------------------------------------------------------------ */

	public static function rewrites() {
		add_rewrite_rule( '^brand\.md$', 'index.php?brand_pack=md', 'top' );
		add_rewrite_rule( '^\.well-known/brand\.json$', 'index.php?brand_pack=json', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = 'brand_pack';
		return $vars;
	}

	/**
	 * Readable by anyone when public; otherwise by editors, or with the access key
	 * as a Bearer header or ?key= (many agents can fetch a URL but not set headers).
	 */
	public static function can_read( $request = null ) {
		$o = self::options();
		if ( $o['public'] || current_user_can( 'edit_posts' ) ) {
			return true;
		}

		$key  = '';
		$auth = $request ? $request->get_header( 'authorization' ) : ( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' );
		if ( $auth && preg_match( '/^Bearer\s+(\S+)$/i', $auth, $m ) ) {
			$key = $m[1];
		} elseif ( $request && $request->get_param( 'key' ) ) {
			$key = (string) $request->get_param( 'key' );
		} elseif ( isset( $_GET['key'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		}

		return '' !== $key && hash_equals( $o['token'], $key );
	}

	public static function serve() {
		$format = get_query_var( 'brand_pack' );
		if ( ! $format ) {
			return;
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex' );

		if ( ! self::can_read() ) {
			status_header( 401 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo "This brand pack is private. Send the access key as an Authorization: Bearer header, or add ?key= to the URL.\n";
			exit;
		}

		status_header( 200 );
		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( self::get_pack(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} else {
			header( 'Content-Type: text/markdown; charset=utf-8' );
			echo self::get_markdown(); // phpcs:ignore WordPress.Security.EscapeOutput -- plain text response.
		}
		exit;
	}

	public static function rest_routes() {
		register_rest_route(
			'brand-pack/v1',
			'/pack',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'args'                => array(
					'format' => array(
						'type'    => 'string',
						'enum'    => array( 'json', 'markdown' ),
						'default' => 'json',
					),
				),
				'callback'            => function ( $request ) {
					if ( 'markdown' === $request['format'] ) {
						return array( 'markdown' => self::get_markdown() );
					}
					return self::get_pack();
				},
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Abilities (exposed to MCP clients through the MCP adapter)
	 * ------------------------------------------------------------------ */

	public static function ability_category() {
		wp_register_ability_category(
			'brand-pack',
			array(
				'label'       => __( 'Brand', 'brand-pack' ),
				'description' => __( 'The site\'s brand pack: voice, visual identity, rules and facts.', 'brand-pack' ),
			)
		);
	}

	public static function abilities() {
		$meta = array(
			'show_in_rest' => true,
			'annotations'  => array( 'readonly' => true, 'idempotent' => true ),
			'mcp'          => array( 'public' => true ),
		);

		wp_register_ability(
			'brand-pack/get-pack',
			array(
				'label'               => __( 'Get brand pack', 'brand-pack' ),
				'description'         => __( 'Returns the brand pack: voice, words to use and avoid, hard rules, calls to action, examples, colours, fonts, logo, topics and writing samples. Read it before writing, designing or reviewing anything for this site.', 'brand-pack' ),
				'category'            => 'brand-pack',
				'input_schema'        => array(
					'type'       => 'object',
					'default'    => array( 'format' => 'markdown' ),
					'properties' => array(
						'format' => array(
							'type'        => 'string',
							'enum'        => array( 'markdown', 'json' ),
							'default'     => 'markdown',
							'description' => 'markdown for reading, json for structured fields.',
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => function ( $input ) {
					if ( 'json' === ( $input['format'] ?? 'markdown' ) ) {
						return self::get_pack();
					}
					return array( 'markdown' => self::get_markdown() );
				},
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'meta'                => $meta,
			)
		);

		wp_register_ability(
			'brand-pack/check-text',
			array(
				'label'               => __( 'Check text against the brand', 'brand-pack' ),
				'description'         => __( 'Checks a draft for words the brand never uses, competitors it never mentions and missing calls to action. Returns the hard rules too, so you can judge tone and claims yourself.', 'brand-pack' ),
				'category'            => 'brand-pack',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'text' ),
					'properties' => array(
						'text' => array(
							'type'        => 'string',
							'description' => 'The draft to check (plain text, Markdown or HTML).',
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => fn( $input ) => self::check_text( $input['text'] ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'meta'                => $meta,
			)
		);
	}

	public static function check_text( $text ) {
		$pack  = self::get_pack();
		$plain = wp_strip_all_tags( $text );
		$find  = function ( $terms ) use ( $plain ) {
			$hits = array();
			foreach ( $terms as $term ) {
				$count = preg_match_all( '/(?<![\p{L}\p{N}])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}])/iu', $plain );
				if ( $count ) {
					$hits[] = array( 'term' => $term, 'count' => $count );
				}
			}
			return $hits;
		};

		$avoid       = $find( $pack['voice']['avoid'] );
		$competitors = $find( $pack['rules']['never_mention'] );
		$has_cta     = false;
		foreach ( $pack['ctas'] as $cta ) {
			if ( stripos( $text, $cta['text'] ) !== false || ( $cta['url'] && stripos( $text, $cta['url'] ) !== false ) ) {
				$has_cta = true;
			}
		}

		return array(
			'passes'           => ! $avoid && ! $competitors,
			'avoided_words'    => $avoid,
			'competitors'      => $competitors,
			'has_approved_cta' => $pack['ctas'] ? $has_cta : null,
			'still_check'      => array(
				'never_claim' => $pack['rules']['never_claim'],
				'disclaimers' => $pack['rules']['disclaimers'],
				'voice'       => $pack['voice']['descriptors'],
			),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Skill export
	 * ------------------------------------------------------------------ */

	public static function skill_slug() {
		$slug = sanitize_title( get_bloginfo( 'name' ) ) ?: 'site';
		return substr( $slug, 0, 57 ) . '-brand';
	}

	public static function download_skill() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'brand-pack' ) );
		}
		check_admin_referer( 'brand_pack_skill' );

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'This server cannot create zip files (the PHP zip extension is missing). Copy the brand.md file instead.', 'brand-pack' ) );
		}

		$pack = self::get_pack();
		$slug = self::skill_slug();
		$name = $pack['site']['name'];

		$skill  = "---\n";
		$skill .= "name: {$slug}\n";
		$skill .= 'description: ' . wp_json_encode( "Brand pack for {$name}: voice, words to use and avoid, hard rules, colours, fonts, logo and writing samples. Use whenever writing, designing or reviewing anything published as {$name}: blog posts, pages, emails, social posts, ads, product copy or images." ) . "\n";
		$skill .= "---\n\n";
		$skill .= self::get_markdown();
		$skill .= "\n\n## Files\n\n- `brand.json` holds the same pack as structured data.\n";

		$logo      = $pack['visual']['logo_id'] ? get_attached_file( $pack['visual']['logo_id'] ) : '';
		$logo_name = $logo && is_readable( $logo ) ? 'assets/logo.' . pathinfo( $logo, PATHINFO_EXTENSION ) : '';
		if ( $logo_name ) {
			$skill .= "- `{$logo_name}` is the logo.\n";
		}

		$file = wp_tempnam( $slug . '.zip' );
		$zip  = new ZipArchive();
		$zip->open( $file, ZipArchive::OVERWRITE );
		$zip->addFromString( "{$slug}/SKILL.md", $skill );
		$zip->addFromString( "{$slug}/brand.json", wp_json_encode( $pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		if ( $logo_name ) {
			$zip->addFile( $logo, "{$slug}/{$logo_name}" );
		}
		$zip->close();

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $slug . '.zip"' );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $file );
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Admin
	 * ------------------------------------------------------------------ */

	public static function menu() {
		add_menu_page(
			__( 'Brand Pack', 'brand-pack' ),
			__( 'Brand Pack', 'brand-pack' ),
			'manage_options',
			'brand-pack',
			array( __CLASS__, 'page' ),
			'dashicons-art',
			61
		);
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_brand-pack' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'brand-pack', plugins_url( 'assets/admin.css', __FILE__ ), array(), self::VERSION );
		wp_enqueue_script( 'brand-pack', plugins_url( 'assets/admin.js', __FILE__ ), array(), self::VERSION, true );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'brand-pack' ) );
		}
		check_admin_referer( 'brand_pack_save' );

		$in  = wp_unslash( $_POST );
		$o   = self::options();
		$tab = sanitize_key( $in['tab'] ?? 'brand' );

		if ( isset( $in['access'] ) ) {
			$o['public'] = 'public' === $in['access'];
		}

		if ( 'brand' !== $tab ) {
			update_option( self::OPTION, $o, false );
			wp_safe_redirect( add_query_arg( array( 'page' => 'brand-pack', 'tab' => $tab, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}

		foreach ( self::FIELDS as $key => $field ) {
			$value = (string) ( $in[ $key ] ?? '' );
			if ( 'list' === $field['type'] ) {
				$o[ $key ] = array_values( array_filter( array_map( 'sanitize_text_field', explode( "\n", $value ) ) ) );
			} else {
				$o[ $key ] = sanitize_textarea_field( $value );
			}
		}

		$o['examples'] = array();
		foreach ( (array) ( $in['examples'] ?? array() ) as $ex ) {
			$ex = array_map( 'sanitize_textarea_field', array_merge( array( 'bad' => '', 'good' => '', 'why' => '' ), (array) $ex ) );
			if ( '' !== trim( $ex['good'] ) ) {
				$o['examples'][] = array_intersect_key( $ex, array_flip( array( 'bad', 'good', 'why' ) ) );
			}
		}

		$o['samples'] = array_slice( array_map( 'absint', (array) ( $in['samples'] ?? array() ) ), 0, self::SAMPLE_MAX );

		update_option( self::OPTION, $o, false );
		self::mark_dirty();

		wp_safe_redirect( add_query_arg( array( 'page' => 'brand-pack', 'tab' => 'brand', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function regenerate_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'brand-pack' ) );
		}
		check_admin_referer( 'brand_pack_token' );
		$o          = self::options();
		$o['token'] = wp_generate_password( 32, false );
		update_option( self::OPTION, $o, false );
		wp_safe_redirect( admin_url( 'admin.php?page=brand-pack&tab=connect&token=1' ) );
		exit;
	}

	public static function page() {
		$tabs = array(
			'brand'   => __( 'Brand', 'brand-pack' ),
			'preview' => __( 'Preview', 'brand-pack' ),
			'connect' => __( 'Connect agents', 'brand-pack' ),
			'history' => __( 'History', 'brand-pack' ),
		);
		$tab  = sanitize_key( $_GET['tab'] ?? 'brand' );
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'brand';
		?>
		<div class="wrap brand-pack">
			<h1><?php esc_html_e( 'Brand Pack', 'brand-pack' ); ?></h1>
			<p class="brand-pack__lede"><?php esc_html_e( 'Everything an AI agent needs to write and design as you. Most of it comes from your site; add what only you know.', 'brand-pack' ); ?></p>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved. Your brand pack is up to date.', 'brand-pack' ); ?></p></div>
			<?php elseif ( isset( $_GET['token'] ) ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'New access key created. Agents using the old key can no longer read your brand pack.', 'brand-pack' ); ?></p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=brand-pack&tab=' . $key ) ); ?>" class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			call_user_func( array( __CLASS__, 'tab_' . $tab ) );
			?>
		</div>
		<?php
	}

	private static function tab_brand() {
		$o    = self::options();
		$pack = self::get_pack();
		$vis  = $pack['visual'];
		?>
		<div class="brand-pack__columns">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="brand-pack__form">
				<input type="hidden" name="action" value="brand_pack_save">
				<input type="hidden" name="tab" value="brand">
				<?php wp_nonce_field( 'brand_pack_save' ); ?>

				<?php foreach ( self::FIELDS as $key => $field ) : ?>
					<div class="brand-pack__field">
						<label for="bp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
						<p class="description"><?php echo esc_html( $field['help'] ); ?></p>
						<textarea id="bp-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" rows="<?php echo 'list' === $field['type'] ? 4 : 3; ?>"><?php echo esc_textarea( 'list' === $field['type'] ? implode( "\n", $o[ $key ] ) : $o[ $key ] ); ?></textarea>
					</div>
				<?php endforeach; ?>

				<div class="brand-pack__field">
					<label><?php esc_html_e( 'Examples', 'brand-pack' ); ?></label>
					<p class="description"><?php esc_html_e( 'Show an agent the difference. A "not this / this" pair teaches tone faster than any rule.', 'brand-pack' ); ?></p>
					<div class="brand-pack__examples" data-next="<?php echo count( $o['examples'] ); ?>">
						<?php
						foreach ( $o['examples'] as $i => $ex ) {
							self::example_row( $i, $ex );
						}
						?>
					</div>
					<template id="brand-pack-example"><?php self::example_row( '__i__', array() ); ?></template>
					<button type="button" class="button brand-pack__add-example"><?php esc_html_e( 'Add example', 'brand-pack' ); ?></button>
				</div>

				<div class="brand-pack__field">
					<label><?php esc_html_e( 'Writing samples', 'brand-pack' ); ?></label>
					<p class="description">
						<?php
						/* translators: %d: number of posts. */
						printf( esc_html__( 'Pick up to %d posts that sound most like you. Leave all unticked to use your latest posts.', 'brand-pack' ), (int) self::SAMPLE_MAX );
						?>
					</p>
					<div class="brand-pack__samples">
						<?php
						$recent = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 30 ) );
						$ids    = wp_list_pluck( $recent, 'ID' );
						$extra  = array_diff( $o['samples'], $ids );
						if ( $extra ) {
							$recent = array_merge( get_posts( array( 'post__in' => $extra, 'post_type' => 'post' ) ), $recent );
						}
						foreach ( $recent as $post ) :
							?>
							<label>
								<input type="checkbox" name="samples[]" value="<?php echo (int) $post->ID; ?>" <?php checked( in_array( $post->ID, $o['samples'], true ) ); ?>>
								<?php echo esc_html( get_the_title( $post ) ); ?>
								<span><?php echo esc_html( get_the_date( '', $post ) ); ?></span>
							</label>
						<?php endforeach; ?>
						<?php if ( ! $recent ) : ?>
							<p><?php esc_html_e( 'No published posts yet.', 'brand-pack' ); ?></p>
						<?php endif; ?>
					</div>
				</div>

				<?php submit_button( __( 'Save brand pack', 'brand-pack' ) ); ?>
			</form>

			<aside class="brand-pack__found">
				<h2><?php esc_html_e( 'Pulled from your site', 'brand-pack' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Kept up to date automatically when you change your theme, styles, pages or posts.', 'brand-pack' ); ?></p>

				<h3><?php esc_html_e( 'Identity', 'brand-pack' ); ?></h3>
				<?php if ( $vis['logo'] || $vis['icon'] ) : ?>
					<img class="brand-pack__logo" src="<?php echo esc_url( $vis['logo'] ?: $vis['icon'] ); ?>" alt="">
				<?php endif; ?>
				<p><strong><?php echo esc_html( $pack['site']['name'] ); ?></strong><br><?php echo esc_html( $pack['site']['tagline'] ); ?></p>
				<?php if ( $pack['about'] ) : ?>
					<p class="brand-pack__muted">
						<?php
						/* translators: %s: page title. */
						printf( esc_html__( 'Using your "%s" page.', 'brand-pack' ), esc_html( $pack['about']['title'] ) );
						?>
					</p>
				<?php else : ?>
					<p class="brand-pack__muted"><?php esc_html_e( 'No About page found. Publish one at /about and it will be added.', 'brand-pack' ); ?></p>
				<?php endif; ?>

				<?php if ( $vis['colors'] ) : ?>
					<h3><?php esc_html_e( 'Colours', 'brand-pack' ); ?></h3>
					<ul class="brand-pack__swatches">
						<?php foreach ( $vis['colors'] as $color ) : ?>
							<li><span style="background:<?php echo esc_attr( $color['value'] ); ?>"></span><?php echo esc_html( $color['name'] ); ?> <code><?php echo esc_html( $color['value'] ); ?></code></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( ! empty( $vis['roles']['heading_font'] ) || ! empty( $vis['roles']['body_font'] ) ) : ?>
					<h3><?php esc_html_e( 'Type', 'brand-pack' ); ?></h3>
					<p>
						<?php if ( ! empty( $vis['roles']['heading_font'] ) ) : ?>
							<?php esc_html_e( 'Headings:', 'brand-pack' ); ?> <strong><?php echo esc_html( $vis['roles']['heading_font'] ); ?></strong><br>
						<?php endif; ?>
						<?php if ( ! empty( $vis['roles']['body_font'] ) ) : ?>
							<?php esc_html_e( 'Body:', 'brand-pack' ); ?> <strong><?php echo esc_html( $vis['roles']['body_font'] ); ?></strong>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<?php if ( $pack['topics'] ) : ?>
					<h3><?php esc_html_e( 'Topics', 'brand-pack' ); ?></h3>
					<p><?php echo esc_html( implode( ', ', wp_list_pluck( array_slice( $pack['topics'], 0, 10 ), 'name' ) ) ); ?></p>
				<?php endif; ?>

				<?php if ( $pack['products'] ) : ?>
					<h3><?php esc_html_e( 'Products', 'brand-pack' ); ?></h3>
					<p>
						<?php
						/* translators: %d: number of products. */
						printf( esc_html__( '%d products from WooCommerce.', 'brand-pack' ), count( $pack['products'] ) );
						?>
					</p>
				<?php endif; ?>

				<h3><?php esc_html_e( 'Writing samples', 'brand-pack' ); ?></h3>
				<p>
					<?php
					/* translators: %d: number of posts. */
					printf( esc_html( _n( '%d post', '%d posts', count( $pack['samples'] ), 'brand-pack' ) ), count( $pack['samples'] ) );
					?>
				</p>
			</aside>
		</div>
		<?php
	}

	private static function example_row( $i, $ex ) {
		$ex   = array_merge( array( 'bad' => '', 'good' => '', 'why' => '' ), $ex );
		$name = fn( $k ) => esc_attr( "examples[{$i}][{$k}]" );
		?>
		<div class="brand-pack__example">
			<label><?php esc_html_e( 'Not this', 'brand-pack' ); ?><textarea name="<?php echo $name( 'bad' ); // phpcs:ignore ?>" rows="2"><?php echo esc_textarea( $ex['bad'] ); ?></textarea></label>
			<label><?php esc_html_e( 'This', 'brand-pack' ); ?><textarea name="<?php echo $name( 'good' ); // phpcs:ignore ?>" rows="2"><?php echo esc_textarea( $ex['good'] ); ?></textarea></label>
			<label><?php esc_html_e( 'Why (optional)', 'brand-pack' ); ?><input type="text" name="<?php echo $name( 'why' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $ex['why'] ); ?>"></label>
			<button type="button" class="button-link brand-pack__remove"><?php esc_html_e( 'Remove', 'brand-pack' ); ?></button>
		</div>
		<?php
	}

	private static function tab_preview() {
		$markdown = self::get_markdown();
		?>
		<p>
			<?php
			/* translators: %s: word count. */
			printf( esc_html__( 'This is exactly what agents read. About %s words.', 'brand-pack' ), esc_html( number_format_i18n( str_word_count( $markdown ) ) ) );
			?>
			<button type="button" class="button brand-pack__copy" data-copy="#brand-pack-markdown"><?php esc_html_e( 'Copy', 'brand-pack' ); ?></button>
		</p>
		<pre id="brand-pack-markdown" class="brand-pack__markdown"><?php echo esc_html( $markdown ); ?></pre>
		<?php
	}

	private static function tab_connect() {
		$o    = self::options();
		$md   = home_url( '/brand.md' );
		$json = home_url( '/.well-known/brand.json' );
		$rest = rest_url( 'brand-pack/v1/pack' );
		$key  = $o['token'];
		?>
		<div class="brand-pack__connect">
			<section>
				<h2><?php esc_html_e( '1. Who can read it', 'brand-pack' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="brand_pack_save">
					<input type="hidden" name="tab" value="connect">
					<?php wp_nonce_field( 'brand_pack_save' ); ?>
					<label class="brand-pack__radio"><input type="radio" name="access" value="private" <?php checked( ! $o['public'] ); ?>> <strong><?php esc_html_e( 'Private', 'brand-pack' ); ?></strong> — <?php esc_html_e( 'only agents with your access key (recommended)', 'brand-pack' ); ?></label>
					<label class="brand-pack__radio"><input type="radio" name="access" value="public" <?php checked( $o['public'] ); ?>> <strong><?php esc_html_e( 'Public', 'brand-pack' ); ?></strong> — <?php esc_html_e( 'anyone, like llms.txt. Competitors could read your voice guide.', 'brand-pack' ); ?></label>
					<?php submit_button( __( 'Save', 'brand-pack' ), 'secondary', 'submit', false ); ?>
				</form>

				<?php if ( ! $o['public'] ) : ?>
					<h3><?php esc_html_e( 'Access key', 'brand-pack' ); ?></h3>
					<p class="brand-pack__secret">
						<code id="brand-pack-key" data-secret="<?php echo esc_attr( $key ); ?>">••••••••••••••••</code>
						<button type="button" class="button brand-pack__reveal"><?php esc_html_e( 'Show', 'brand-pack' ); ?></button>
						<button type="button" class="button brand-pack__copy" data-copy-value="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Copy', 'brand-pack' ); ?></button>
						<a class="button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=brand_pack_token' ), 'brand_pack_token' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Agents using the current key will lose access. Continue?', 'brand-pack' ) ); ?>');"><?php esc_html_e( 'Create new key', 'brand-pack' ); ?></a>
					</p>
				<?php endif; ?>
			</section>

			<section>
				<h2><?php esc_html_e( '2. Point your agents at it', 'brand-pack' ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Markdown', 'brand-pack' ); ?></th>
							<td><code><?php echo esc_html( $md ); ?></code></td>
							<td><?php esc_html_e( 'For any agent that can fetch a web page.', 'brand-pack' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'JSON', 'brand-pack' ); ?></th>
							<td><code><?php echo esc_html( $json ); ?></code></td>
							<td><?php esc_html_e( 'Structured fields, for tools and automations.', 'brand-pack' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'REST', 'brand-pack' ); ?></th>
							<td><code><?php echo esc_html( $rest ); ?></code></td>
							<td><?php esc_html_e( 'Add ?format=markdown for Markdown.', 'brand-pack' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'MCP', 'brand-pack' ); ?></th>
							<td><code>brand-pack/get-pack</code><br><code>brand-pack/check-text</code></td>
							<td>
								<?php
								if ( class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
									esc_html_e( 'Available to agents connected through the MCP Adapter.', 'brand-pack' );
								} else {
									esc_html_e( 'Registered as WordPress abilities. Install the MCP Adapter plugin to expose them to MCP clients.', 'brand-pack' );
								}
								?>
							</td>
						</tr>
					</tbody>
				</table>

				<?php if ( ! $o['public'] ) : ?>
					<h3><?php esc_html_e( 'Prompt to paste into an agent', 'brand-pack' ); ?></h3>
					<?php
					$prompt = fn( $k ) => sprintf( 'Before writing anything for %s, fetch %s and follow it. The "Never" section holds hard rules.', html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $md . '?key=' . $k );
					?>
					<pre class="brand-pack__markdown brand-pack__prompt"><?php echo esc_html( $prompt( '••••••••' ) ); ?></pre>
					<button type="button" class="button brand-pack__copy" data-copy-value="<?php echo esc_attr( $prompt( $key ) ); ?>"><?php esc_html_e( 'Copy prompt', 'brand-pack' ); ?></button>
					<p class="description"><?php esc_html_e( 'The prompt contains your access key. Prefer sending it as an "Authorization: Bearer" header when the tool supports headers.', 'brand-pack' ); ?></p>
				<?php endif; ?>
			</section>

			<section>
				<h2><?php esc_html_e( '3. Or download it as a skill', 'brand-pack' ); ?></h2>
				<p><?php esc_html_e( 'A folder with SKILL.md, brand.json and your logo. Drop it into Claude Code (~/.claude/skills), upload it to Claude, or add it to any agent that reads skills. Download again after big changes.', 'brand-pack' ); ?></p>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=brand_pack_skill' ), 'brand_pack_skill' ) ); ?>">
					<?php
					/* translators: %s: file name. */
					printf( esc_html__( 'Download %s.zip', 'brand-pack' ), esc_html( self::skill_slug() ) );
					?>
				</a>
			</section>
		</div>
		<?php
	}

	private static function tab_history() {
		self::get_pack();
		$history = get_option( self::HISTORY, array() );
		$view    = isset( $_GET['v'] ) ? absint( $_GET['v'] ) : null;

		if ( null !== $view && isset( $history[ $view ] ) ) {
			$this_md = $history[ $view ]['markdown'];
			$prev_md = isset( $history[ $view + 1 ] ) ? $history[ $view + 1 ]['markdown'] : '';
			?>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=brand-pack&tab=history' ) ); ?>">&larr; <?php esc_html_e( 'All versions', 'brand-pack' ); ?></a></p>
			<h2>
				<?php
				/* translators: %s: date. */
				printf( esc_html__( 'Changes on %s', 'brand-pack' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $history[ $view ]['time'] ) ) );
				?>
			</h2>
			<?php
			$diff = wp_text_diff( $prev_md, $this_md, array( 'title_left' => __( 'Before', 'brand-pack' ), 'title_right' => __( 'After', 'brand-pack' ) ) );
			echo $diff ? $diff : '<p>' . esc_html__( 'First version.', 'brand-pack' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- core diff markup.
			return;
		}
		?>
		<p><?php esc_html_e( 'A new version is saved whenever your brand pack changes, whether you edited it or your site did.', 'brand-pack' ); ?></p>
		<table class="widefat striped brand-pack__history">
			<thead><tr><th><?php esc_html_e( 'When', 'brand-pack' ); ?></th><th><?php esc_html_e( 'What changed', 'brand-pack' ); ?></th><th></th></tr></thead>
			<tbody>
				<?php foreach ( $history as $i => $entry ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['time'] ) ); ?><?php echo 0 === $i ? ' <span class="brand-pack__badge">' . esc_html__( 'Current', 'brand-pack' ) . '</span>' : ''; ?></td>
						<td><?php echo esc_html( self::change_summary( $entry['markdown'], $history[ $i + 1 ]['markdown'] ?? '' ) ); ?></td>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=brand-pack&tab=history&v=' . $i ) ); ?>"><?php esc_html_e( 'View changes', 'brand-pack' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Names the sections that differ between two versions, e.g. "Voice, Colours".
	 */
	private static function change_summary( $now, $before ) {
		if ( '' === $before ) {
			return __( 'First version', 'brand-pack' );
		}
		$sections = function ( $md ) {
			$out   = array();
			$parts = preg_split( '/^## /m', $md );
			array_shift( $parts );
			foreach ( $parts as $part ) {
				list( $title ) = explode( "\n", $part, 2 );
				$out[ $title ] = md5( $part );
			}
			return $out;
		};
		$a       = $sections( $before );
		$b       = $sections( $now );
		$changed = array();
		foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $title ) {
			if ( ( $a[ $title ] ?? '' ) !== ( $b[ $title ] ?? '' ) ) {
				$changed[] = $title;
			}
		}
		return $changed ? implode( ', ', $changed ) : __( 'Site details', 'brand-pack' );
	}
}

Brand_Pack::init();
