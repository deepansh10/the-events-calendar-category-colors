<?php
class Tribe_Events_Category_Colors_Public {

	const CSS_HANDLE = 'teccc_css';

	protected $teccc   = null;
	protected $options = array();

	protected $legendTargetHook   = 'tribe_events_after_header';
	protected $legendFilterHasRun = false;
	protected $legendExtraView    = array();

	protected $css_added = false;


	public function __construct( Tribe_Events_Category_Colors $teccc ) {
		$this->teccc   = $teccc;
		$this->options = get_option( 'teccc_options' );

		require TECCC_INCLUDES . '/templatetags.php';
		require_once TECCC_CLASSES . '/class-widgets.php';
		require_once TECCC_CLASSES . '/class-extras.php';

		add_action( 'pre_get_posts', array( $this, 'add_colored_categories' ) );
	}


	public function add_colored_categories( $query ) {
		if ( isset( $_GET[self::CSS_HANDLE] ) ) {
			$this->do_css();
		}

		if ( ! isset( $query->query_vars['post_type'] ) ) {
			return false;
		}

		$post_types = array( 'tribe_events', 'tribe_organizer', 'tribe_venue' );
		if ( in_array( $query->query_vars['post_type'], $post_types, true ) ) {
			$this->add_effects();
		}
	}


	public function add_effects() {
		// Possibly add our styles inline, if they are required only for a widget
		if ( isset( $this->options['color_widgets'] ) && '1' === $this->options['color_widgets'] ) {
			add_action( 'tribe_events_before_list_widget', array( $this, 'add_css_inline' ) );
			add_action( 'tribe_events_mini_cal_after_the_grid', array( $this, 'add_css_inline' ) );
			add_action( 'tribe_events_venue_widget_before_the_title', array( $this, 'add_css_inline' ) );
		}

		// Enqueue stylesheet
		add_action( 'wp_enqueue_scripts', array( $this, 'add_css' ), PHP_INT_MAX );

		// Show legend
		add_action( $this->legendTargetHook, array( $this, 'show_legend' ) );

		// Add legend superpowers
		if ( isset( $this->options['legend_superpowers'] ) && '1' === $this->options['legend_superpowers'] && ! wp_is_mobile() ) {
			wp_enqueue_script( 'legend_superpowers', TECCC_RESOURCES . '/legend-superpowers.js', array( 'jquery' ), Tribe_Events_Category_Colors::$version, true );
		}
	}

	/**
	 * By generating a unique hash of the plugin options if these change so will the
	 * stylesheet URL, forcing the browser to grab an updated copy.
	 *
	 * @return string
	 */
	protected function options_hash() {
		return hash( 'md5', join( '|', (array) $this->options ) );
	}

	public function add_css() {
		wp_enqueue_style( 'teccc_stylesheet', add_query_arg( self::CSS_HANDLE, $this->options_hash(), get_site_url( null ) ) );
		$this->css_added = true;
	}

	/**
	 * Adds our CSS inline on pages where we need category coloring for event widgets
	 * but where our stylesheet hasn't been enqueued.
	 *
	 * @todo consider enqueuing assets everywhere and avoid inlining
	 */
	public function add_css_inline() {
		if ( $this->css_added ) {
			return true;
		}

		echo '<style>';
		echo $this->generate_css();
		echo '</style>';

		$this->css_added = true;
	}

	public function do_css() {
		// Use RFC 1123 date format for the expiry time
		$next_year = date( DateTime::RFC1123, strtotime( '+1 year', time() ) );
		$one_year  = 31536000;
		$hash      = $this->options_hash();

		header( "Content-type: text/css" );
		header( "Expires: $next_year" );
		header( "Cache-Control: public, max-age=$one_year" );
		header( "Pragma: public" );
		header( "Etag: $hash" );

		echo $this->generate_css();

		exit();
	}

	protected function generate_css() {
		// Look out for fresh_css requests
		$fresh_css = isset( $_GET['fresh_css'] ) ? $_GET['fresh_css'] : false;

		// Return cached CSS if available and if fresh CSS hasn't been requested
		$cache_key = 'teccc_' . $this->options_hash();
		$css = get_transient( $cache_key );
		if ( $css && ! $fresh_css ) {
			return $css;
		}

		// Else generate the CSS afresh
		ob_start();

		$this->teccc->view( 'category.css', array(
			'options' => $this->options,
			'teccc'   => $this->teccc
		) );

		$css = ob_get_clean();

		// Store in transient
		set_transient( $cache_key, $css, 4 * WEEK_IN_SECONDS );

		return $css;
	}

	public function show_legend( $existingContent = '' ) {
		$tribe         = TribeEvents::instance();
		$eventDisplays = array( 'month' );
		$eventDisplays = array_merge( $eventDisplays, $this->legendExtraView );
		$tribe_view    = get_query_var( 'eventDisplay' );
		if ( isset( $tribe->displaying ) && get_query_var( 'eventDisplay' ) !== $tribe->displaying ) {
			$tribe_view = $tribe->displaying;
		}
		if ( ( 'tribe_events' === get_query_var( 'post_type' ) ) && ! in_array( $tribe_view, $eventDisplays, true ) ) {
			return false;
		}
		if ( ! ( isset( $this->options['add_legend'] ) && '1' === $this->options['add_legend'] ) ) {
			return false;
		}

		$content = $this->teccc->view( 'legend', array(
			'options' => $this->options,
			'teccc'   => Tribe_Events_Category_Colors::instance(),
			'tec'     => TribeEvents::instance()
		), false );

		$this->legendFilterHasRun = true;
		echo $existingContent . apply_filters( 'teccc_legend_html', $content );
	}


	public function reposition_legend( $tribeViewFilter ) {
		// If the legend has already run they are probably doing something wrong
		if ( $this->legendFilterHasRun ) {
			_doing_it_wrong( 'Tribe_Events_Category_Colors_Public::reposition_legend',
			'You are attempting to reposition the legend after it has already been rendered.', '1.6.4' );
		}

		// Change the target filter (even if they are _doing_it_wrong, in case they have a special use case)
		$this->legendTargetHook = $tribeViewFilter;

		// Indicate if they were doing it wrong (or not)
		return ( ! $this->legendFilterHasRun );
	}


	public function remove_default_legend() {
		// If the legend has already run they are probably doing something wrong
		if( $this->legendFilterHasRun ) {
			_doing_it_wrong( 'Tribe_Events_Category_Colors_Public::reposition_legend',
			'You are attempting to remove the default legend after it has already been rendered.', '1.6.4' );
		}

		// Remove the hook regardless of whether they are _doing_it_wrong or not (in case of creative usage)
		$this->legendTargetHook = null;

		// Indicate if they were doing it wrong (or not)
		return ( ! $this->legendFilterHasRun );
	}
	
	public function add_legend_view( $view ) {
		//takes 'upcoming', 'day', 'week', 'photo' as parameters
		$this->legendExtraView[] = $view;
	}

}