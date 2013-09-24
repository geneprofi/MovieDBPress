<?php
/*
Plugin Name: TMDb API
Plugin URI: http://interconnectit.com
Description: This plugin provides a way to use themoviedb.org API within wordpress. It adds a UI for searching a movie and provides a means of inserting trailers or images into posts.
Author: Robert O'Rourke
Version: 0.2
Author URI: http://interconnectit.com
*/

if ( ! defined( 'TMDB_PLUGIN_URL' ) )
	define( 'TMDB_PLUGIN_URL', plugins_url( '', __FILE__ ) );

if ( ! defined( 'TMDB_PLUGIN_BASE' ) )
	define( 'TMDB_PLUGIN_BASE', basename( __FILE__ ) );

add_image_size( 'tmdb-thumb', 150, 150, false ); // natural size within a box

// include API wrapper
require_once 'api/TMDb.php';

// initialise
add_action( 'plugins_loaded', array( 'tmdb_api', 'instance' ) );
register_activation_hook( __FILE__, array( 'tmdb', 'activate' ) );

class tmdb_api {

	public static $api;
	public static $api_key;
	private $done_save = false;

	/**
	 * Reusable object instance.
	 *
	 * @type object
	 */
	protected static $instance = null;


	/**
	 * Creates a new instance. Called on 'after_setup_theme'.
	 * May be used to access class methods from outside.
	 *
	 * @see    __construct()
	 * @return void
	 */
	public static function instance() {
		null === self :: $instance AND self :: $instance = new self;
		return self :: $instance;
	}


	function __construct() {

		// set language
		$this->language = get_locale();

		// set API key
		$this->set( 'api_key', get_option( 'tmdb_api_key' ) );

		// set API as usable TMDB object
		$this->api = new TMDb( $this->api_key, $this->language, false, TMDb::API_SCHEME );

		// store TMDb config
		if ( ! is_object( $this->config = get_transient( 'tmdb_config' ) ) && $this->get( 'api_key' ) ) {
			$this->config = $this->a2o( $this->api->getConfiguration() );
			set_transient( 'tmdb_config', $this->config, (30*24*60*60) );
		}

		// WP
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );

		// display hooks
		add_filter( 'post_class', array( $this, 'post_class' ), 10, 3 );

		// plugin settings link
		add_filter( 'plugin_action_links', array( $this, 'add_settings_link' ), 10, 2 );

		// auto populate taxonomies
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'wp_ajax_media_sideload_image', array( $this, 'ajax_media_sideload_image' ) );
		add_action( 'wp_ajax_tmdb_finished_download', array( $this, 'ajax_finished_download' ) );

	}

	// getter/setter
	private function get( $property ) {
		return $this->$property;
	}
	private function set( $property, $value ) {
		$this->$property = $value;
	}


	/**
	 * WP caching layer on top of TMDb
	 *
	 * @param string 	$method 	The TMDb method to call
	 * @param mixed 	$args   	The args to pass to the method if any
	 * @param int 		$expiration How long the api call should be cached for
	 *
	 * @return mixed    Array response or exception
	 */
	function api_cache( $method, $args = '', $expiration = 3600 ) {

		$result = false;

		// transient key
		$key = 'tmdb_api_' . md5( $method . maybe_serialize( $args ) );

		// check store
		if ( ! $result = get_transient( $key ) ) {
			if ( is_array( $args ) )
				$result = call_user_method_array( $method, $this->api, $args );
			else
				$result = $this->api->$method( $args );

			// store result
			set_transient( $key, $this->a2o( $result ), $expiration );
		}

		if ( $result )
			return $this->a2o( $result );

		return false;
	}


	/**
	 * Hack function to use associative array from TMDb as object
	 *
	 * @param array $array The array to convert to an object
	 *
	 * @return object    An object version of the associative array
	 */
	public function a2o( $array ) {
		return json_decode( json_encode( $array ) );
	}


	/**
	 * Return the TMDB image path from config
	 *
	 * @param string 	$path 	The TMDb image path value
	 * @param string 	$size 	The image size string eg. w1280, w500 etc...
	 * @param bool 		$ssl  	Whether to use the https URL
	 *
	 * @return string    Full URL to tmdb image
	 */
	function image_url( $path, $size = 'original', $ssl = false ) {
		if ( $ssl )
			$url = $this->config->images->secure_base_url;
		else
			$url = $this->config->images->base_url;

		return "{$url}{$size}{$path}";
	}


	// TMDb API settings
	function admin_init() {

		// scripts and styles
		wp_enqueue_style( 'tmdb', TMDB_PLUGIN_URL . '/css/admin.css' );

		// register settings
		add_settings_section( 'tmdb', __( 'The Movie Database&trade;' ), array( $this, 'settings' ), 'media' );

		register_setting( 'media', 'tmdb_api_key', array( $this, 'test_key' ) );
		add_settings_field( 'tmdb_api_key', __('API Key'), array( $this, 'api_key_field' ), 'media', 'tmdb' );

		// image sizes to try downloading
		//register_setting( 'media', 'tmdb_poster_size', array( $this, 'test_key' ) );
		//add_settings_field( 'tmdb_api_key', __('API Key'), array( $this, 'api_key_field' ), 'media', 'tmdb' );

		// add meta box to post types
		foreach( get_post_types( array( 'show_ui' => true ) ) as $post_type ) {
			if ( ! post_type_supports( $post_type, 'movie' ) ) continue;
			// meta box to get movie info and images etc...
			add_meta_box( 'tmdb', __( 'Get movie data' ), array( $this, 'get_movie_data_box' ), $post_type, 'normal', 'high' );
		}


	}

	function settings() { ?>
		<p id="tmdb-intro"><?php printf( __( 'You\'ll need to request an API key from The Movie Database. Sign up for an account %s and then click the "Want to generate an API key?" link under Account Settings.' ), '<a href="http://www.themoviedb.org/account/signup">' . __( 'here' ) . '</a>' ); ?></p>
		<?php
	}

	function api_key_field() { ?>
		<input class="regular-text code" type="text" name="tmdb_api_key" value="<?php esc_attr_e( get_option( 'tmdb_api_key' ) ); ?>" />
		<?php
		if ( get_option( 'tmdb_valid_key' ) ) {
			echo '<p>' . __( 'Sweet, your API key is valid!' ) . '</p>';
		} else {
			echo '<p class="error">' . __( 'Your API key is not valid! Please double check it or contact TMDb API support if the problem persists.' ) . '</p>';
		}
	}

	// test and save
	function test_key( $api_key ) {

		// try new api key
		$api = new TMDb( $api_key );
		$test = $this->a2o( $api->getConfiguration() );

		if ( ! empty( $api_key ) && is_object( $test ) && isset( $test->status_code ) && $test->status_code == 7 ) {
			add_settings_error( 'tmdb_api_key', $test->status_code, $test->status_message );
			delete_option( 'tmdb_valid_key' );
		} else {
			if ( ! get_option( 'tmdb_valid_key', false ) )
				add_option( 'tmdb_valid_key', true );
			update_option( 'tmdb_valid_key', true );
		}

		return sanitize_key( $api_key );
	}

	function add_settings_link( $links, $file ) {
		if ( $file == plugin_basename( __FILE__ ) )
			array_unshift( $links , '<a href="' . admin_url( 'options-media.php#tmdb-intro' ) . '">' . __( "Settings" ) . '</a>' );
		return $links;
	}

	function activate() {

	}


	/**
	 * Admin + Non admin plugin setup
	 */
	function init() {

		//


		// default post type
		register_post_type( 'movie', apply_filters( 'movie_post_type_args', array(
			'labels' => array(
				'name' => _x( 'Movies', 'post type general name' ),
				'singular_name' => _x( 'Movie', 'post type singular name' ),
				'add_new' => _x( 'Add New', 'movie' ),
				'add_new_item' => __( 'Add New Movie' ),
				'edit_item' => __( 'Edit Movie' ),
				'new_item' => __( 'New Movie' ),
				'all_items' => __( 'All Movies' ),
				'view_item' => __( 'View Movie' ),
				'search_items' => __( 'Search Movies' ),
				'not_found' =>  __( 'No movies found' ),
				'not_found_in_trash' => __( 'No movies found in Trash' ),
				'parent_item_colon' => '',
				'menu_name' => __( 'Movies' )
				),
			'public' => true,
			'publicly_queryable' => true,
			'show_ui' => true,
			'show_in_menu' => true,
			'query_var' => true,
			'rewrite' => array( 'slug' => get_option( 'movie_slug', 'movies' ), 'with_front' => false ),
			'capability_type' => 'post',
			'has_archive' => get_option( 'movie_slug', 'movies' ),
			'hierarchical' => false,
			'menu_position' => null,
			'supports' => array( 'title', 'editor', 'movie', 'thumbnail', 'excerpt', 'comments' )
		) ) );

		// taxonomies
		//  - genre
		//  - actor
		//  - director
		//  - author
		//  - certification

		// Genres
		$labels = array(
		  'name' => _x( 'Genres', 'taxonomy general name' ),
		  'singular_name' => _x( 'Genre', 'taxonomy singular name' ),
		  'search_items' =>  __( 'Search Genres' ),
		  'popular_items' => __( 'Popular Genres' ),
		  'all_items' => __( 'All Genres' ),
		  'parent_item' => null,
		  'parent_item_colon' => null,
		  'edit_item' => __( 'Edit Genre' ),
		  'update_item' => __( 'Update Genre' ),
		  'add_new_item' => __( 'Add New Genre' ),
		  'new_item_name' => __( 'New Genre Name' ),
		  'separate_items_with_commas' => __( 'Separate genres with commas' ),
		  'add_or_remove_items' => __( 'Add or remove genre' ),
		  'choose_from_most_used' => __( 'Choose from the most used genres' ),
		  'menu_name' => __( 'Genres' ),
		);

		register_taxonomy( 'movie_genre', 'movie', array(
		  'hierarchical' => false,
		  'labels' => $labels,
		  'show_ui' => true,
		  'update_count_callback' => '_update_post_term_count',
		  'query_var' => true,
		  'rewrite' => array( 'slug' => 'genres', 'with_front' => false ),
		) );

		// Actors
		$labels = array(
		  'name' => _x( 'Actors', 'taxonomy general name' ),
		  'singular_name' => _x( 'Actor', 'taxonomy singular name' ),
		  'search_items' =>  __( 'Search Actors' ),
		  'popular_items' => __( 'Popular Actors' ),
		  'all_items' => __( 'All Actors' ),
		  'parent_item' => null,
		  'parent_item_colon' => null,
		  'edit_item' => __( 'Edit Actor' ),
		  'update_item' => __( 'Update Actor' ),
		  'add_new_item' => __( 'Add New Actor' ),
		  'new_item_name' => __( 'New Actor Name' ),
		  'separate_items_with_commas' => __( 'Separate actors with commas' ),
		  'add_or_remove_items' => __( 'Add or remove actor' ),
		  'choose_from_most_used' => __( 'Choose from the most used actors' ),
		  'menu_name' => __( 'Actors' ),
		);

		register_taxonomy( 'movie_actor', 'movie', array(
		  'hierarchical' => false,
		  'labels' => $labels,
		  'show_ui' => true,
		  'update_count_callback' => '_update_post_term_count',
		  'query_var' => true,
		  'rewrite' => array( 'slug' => 'actors', 'with_front' => false ),
		) );

		// Directors
		$labels = array(
		  'name' => _x( 'Directors', 'taxonomy general name' ),
		  'singular_name' => _x( 'Director', 'taxonomy singular name' ),
		  'search_items' =>  __( 'Search Directors' ),
		  'popular_items' => __( 'Popular Directors' ),
		  'all_items' => __( 'All Directors' ),
		  'parent_item' => null,
		  'parent_item_colon' => null,
		  'edit_item' => __( 'Edit Director' ),
		  'update_item' => __( 'Update Director' ),
		  'add_new_item' => __( 'Add New Director' ),
		  'new_item_name' => __( 'New Director Name' ),
		  'separate_items_with_commas' => __( 'Separate directors with commas' ),
		  'add_or_remove_items' => __( 'Add or remove director' ),
		  'choose_from_most_used' => __( 'Choose from the most used directors' ),
		  'menu_name' => __( 'Directors' ),
		);

		register_taxonomy( 'movie_director', 'movie', array(
		  'hierarchical' => false,
		  'labels' => $labels,
		  'show_ui' => true,
		  'update_count_callback' => '_update_post_term_count',
		  'query_var' => true,
		  'rewrite' => array( 'slug' => 'directors', 'with_front' => false ),
		) );

		// Authors
		$labels = array(
		  'name' => _x( 'Writers', 'taxonomy general name' ),
		  'singular_name' => _x( 'Writer', 'taxonomy singular name' ),
		  'search_items' =>  __( 'Search writers' ),
		  'popular_items' => __( 'Popular writers' ),
		  'all_items' => __( 'All writers' ),
		  'parent_item' => null,
		  'parent_item_colon' => null,
		  'edit_item' => __( 'Edit writer' ),
		  'update_item' => __( 'Update writer' ),
		  'add_new_item' => __( 'Add New writer' ),
		  'new_item_name' => __( 'New writer Name' ),
		  'separate_items_with_commas' => __( 'Separate writers with commas' ),
		  'add_or_remove_items' => __( 'Add or remove writer' ),
		  'choose_from_most_used' => __( 'Choose from the most used writers' ),
		  'menu_name' => __( 'Writers' ),
		);

		register_taxonomy( 'movie_writer', 'movie', array(
		  'hierarchical' => false,
		  'labels' => $labels,
		  'show_ui' => true,
		  'update_count_callback' => '_update_post_term_count',
		  'query_var' => true,
		  'rewrite' => array( 'slug' => 'writers', 'with_front' => false ),
		) );

		// Certification
		$labels = array(
		  'name' => _x( 'Certificate', 'taxonomy general name' ),
		  'singular_name' => _x( 'Certificate', 'taxonomy singular name' ),
		  'search_items' =>  __( 'Search Certifications' ),
		  'popular_items' => __( 'Popular Certifications' ),
		  'all_items' => __( 'All Certifications' ),
		  'parent_item' => null,
		  'parent_item_colon' => null,
		  'edit_item' => __( 'Edit Certification' ),
		  'update_item' => __( 'Update Certification' ),
		  'add_new_item' => __( 'Add New Certification' ),
		  'new_item_name' => __( 'New Certification Name' ),
		  'separate_items_with_commas' => __( 'Separate certifications with commas' ),
		  'add_or_remove_items' => __( 'Add or remove certification' ),
		  'choose_from_most_used' => __( 'Choose from the most used certificates' ),
		  'menu_name' => __( 'Certificate' ),
		);

		register_taxonomy( 'movie_certificate', 'movie', array(
		  'hierarchical' => false,
		  'labels' => $labels,
		  'show_ui' => true,
		  'update_count_callback' => '_update_post_term_count',
		  'query_var' => true,
		  'rewrite' => array( 'slug' => 'certificate', 'with_front' => false ),
		) );

		// incase the movie data should be associated with something else entirely
		$post_types = array();
		foreach( get_post_types() as $post_type ) {
			if ( post_type_supports( $post_type, 'movie' ) )
				$post_types[] = $post_type;
		}

		// associate things with things
		foreach( $post_types as $post_type ) {
			register_taxonomy_for_object_type( 'movie_genre', $post_type );
			register_taxonomy_for_object_type( 'movie_actor', $post_type );
			register_taxonomy_for_object_type( 'movie_director', $post_type );
			register_taxonomy_for_object_type( 'movie_writer', $post_type );
			register_taxonomy_for_object_type( 'movie_certificate', $post_type );
		}

	}



	function get_movie_data_box( $post ) {

		$movie_search = get_post_meta( $post->ID, 'tmdb_movie_search', true );
		$movies = get_post_meta( $post->ID, 'tmdb_movies', true );
		$movie_id = intval( get_post_meta( $post->ID, 'tmdb_movie_id', true ) );
		$movie_data = get_post_meta( $post->ID, 'tmdb_movie_data', true );
		$movie_trailers = get_post_meta( $post->ID, 'tmdb_movie_trailers' );
		$movie_trailer = get_post_meta( $post->ID, 'tmdb_movie_trailer', true );
		$movie_country = get_post_meta( $post->ID, 'tmdb_movie_country', true );
		$movie_images = get_post_meta( $post->ID, 'tmdb_movie_images', true );

		?>
		<p><?php _e( 'Enter the movie title and click go to grab images and info.' ); ?></p>
		<p><input type="text" size="60" name="tmdb_movie_search" value="<?php esc_attr_e( $movie_search ); ?>" /> <input type="submit" class="button" name="tmdb" value="<?php _e( 'Search movie database' ); ?>" /></p><?php
		if ( empty( $post->post_title ) ) { ?>
		<p><?php _e( 'You need to enter a title for the post before you can search the movie database.' ); ?></p><?php
		}

		if ( $movies && ! $movie_id ) {

			$n = 0; ?>
		<p><?php _e( 'Select the movie you want to attach to this post.' ); ?></p>
		<ul>
			<?php foreach( $movies->results as $movie ) { ?>
				<li><label><input <?php if ( $n == 0 ) echo ' checked="checked"'; ?> type="radio" name="tmdb_movie" value="<?php esc_attr_e( $movie->id ) ?>" /> <?php esc_html_e( $movie->title ); ?>, <?php echo date( "Y", strtotime( $movie->release_date ) ); ?></label></li>
			<?php $n++; } ?>
		</ul>
		<input class="button" type="submit" name="tmdb_select" value="<?php _e( 'Select movie' ); ?>" />
		<?php
		}

		if ( $movie_data ) {

			$posters = array();
			$backdrops = array();
			if ( ! empty( $movie_data->backdrop_path ) )
				$backdrops[] = $this->image_url( $movie_data->backdrop_path );
			if ( ! empty( $movie_data->poster_path ) )
				$posters[] = $this->image_url( $movie_data->poster_path );
			if ( ! empty( $movie_data->images->backdrops ) ) {
				foreach( $movie_data->images as $image )
					$backdrops[] = $this->image_url( $image->file_path );
			}
			if ( ! empty( $movie_data->images->posters ) ) {
				foreach( $movie_data->images->posters as $image )
					$backdrops[] = $this->image_url( $image->file_path );
			}

			?>

			<h4>Movie selected: <?php esc_html_e( $movie_data->title ); ?></h4>
			<p>You can search again to find new movie data.</p>

			<div class="tmdb-region">
				<h4>Release region:</h4>
				<p>Select a country to set movie certificate and release date if available. Otherwise you'll need to do it manually.</p>
				<select name="tmdb_release_country">
				<?php foreach( $movie_data->releases->countries as $release ) {
					if ( ! isset( $release->certification ) || empty( $release->certification ) ) continue; ?>
					<option value="<?php echo $release->iso_3166_1; ?>" <?php selected( $release->iso_3166_1, $movie_country ); ?>>
						<?php echo ucwords( strtolower( $this->iso_3166_1_to_text( $release->iso_3166_1 ) ) ) . ' (' . $release->certification . ')'; ?></option>
				<?php } ?>
				</select>
				<input class="button" type="submit" name="tmdb_release_select" value="Select" />
			</div>

			<div class="tmdb-images-wrap">
				<h4>Images:</h4>
				<?php if ( ! $movie_images ) { ?>
				<p id="tmdb-get-images-wrap">
					<?php printf( __( 'There are %d poster images and %d backdrops for this movie' ), count( $posters ), count( $backdrops ) ); ?>
					<input id="tmdb-get-images" class="button" type="submit" name="tmdb_get_images" value="<?php _e( 'Grab images' ); ?>" />
				</p>
				<script>
					(function($){
						var images = [ "<?php
							echo implode( '","', $backdrops );
							echo '","';
							echo implode( '","', $posters );
						?>" ],
							num = 0,
							getting_images = false;
						$( '#tmdb-get-images' ).click( function() {
							$( this ).attr( 'disabled', 'disabled' ).attr( 'value', 'Fetching...' ).after( ' <img class="loader" src="images/wpspin_light.gif" alt="" />' );
							getting_images = true;
							get_next_image();
							return false;
						} );

						function get_next_image() {
							$.post( ajaxurl, {
								action: 'media_sideload_image',
								url: images[num],
								post_id: <?php echo $post->ID; ?>,
								size: 'tmdb-thumb',
								_wpnonce: '<?php echo wp_create_nonce( 'media_sideload_image-' . $post->ID ); ?>'
							}, function( data ) {
								if ( data != 0 ) {
									$( '#tmdb-images' ).append( data );
								}
								// finished downloading
								if ( num == images.length - 1 ) {
									$( '#tmdb-get-images-wrap' ).remove();
									getting_images = false;
									image_ids = [];
									$( '#tmdb-images img[data-attachment-id]' ).each(function(){
										image_ids.push( $(this).data('attachment-id') );
									});
									$.post( ajaxurl, {
										action: 'tmdb_finished_download',
										images: image_ids,
										post_id: <?php echo $post->ID; ?>
									} );
								// keep going
								} else {
									num++;
									get_next_image();
								}
							} );
						}

						// prevent user navigating away while images downloading
						$( document ).bind( 'unload', function(e) {
							if ( getting_images )
								return confirm( 'Are you sure want to navigate away? The images are still downloading.' );
						} );
					})(jQuery);
				</script>
				<?php } ?>
				<p><?php _e( 'Once the images have been downloaded you can set one as your featured image or insert them into the post as you want via the add media button.' ); ?></p>
				<div id="tmdb-images">
					<?php if ( ! empty( $movie_images ) ) {
						foreach( $movie_images as $image_id ) {
							echo '<span class="img-wrap">'. wp_get_attachment_image( intval( $image_id ), 'tmdb-thumb', false, array( 'data-attachment-id' => $image_id ) ) .'</span>';
						}
					} ?>
				</div>
			</div>

			<div id="tmdb-trailer-wrap">
				<h4>Trailer:</h4>
				<?php if ( ! empty( $movie_trailers ) ) { ?>
				<div class="tmdb-trailer-select">
					<p>Select a trailer and hit select to see a preview</p>
					<select name="tmdb_movie_trailer_select">
					<?php foreach( $movie_trailers as $trailer ) {
						$link = 'http://www.youtube.com/watch?v=' . $trailer->source; ?>
						<option <?php selected( $link, $movie_trailer ); ?> value="<?php esc_attr_e( $link ); ?>"><?php _e( $trailer->name ); ?></option>
					<?php } ?>
					</select>
					<input type="submit" name="tmdb_movie_trailer_select_submit" value="Select" />
				</div>
				<?php } ?>
				<div class="tmdb-trailer-url">
					<p>
						<label for="tmdb-movie-trailer"><?php _e( 'Movie trailer link' ); ?></label>
						<input class="widefat" type="text" name="tmdb_movie_trailer" id="tmdb-movie-trailer" value="<?php esc_attr_e( $movie_trailer ); ?>" />
					</p>
					<p class="description">You can choose your own trailer link by editing this field.</p>
				</div>
				<div class="tmdb-trailer">
				<?php
					if ( ! empty( $movie_trailer ) )
						echo wp_oembed_get( $movie_trailer );
				?>
				</div>
			</div>
		<?php
		}
	}

	// ajax upload from url
	function ajax_media_sideload_image() {

		// get params
		$post_id = intval( $_REQUEST[ 'post_id' ] );
		$url = esc_url_raw( $_REQUEST[ 'url' ] );
		$filename = explode( "/", $url );
		$size = isset( $_REQUEST[ 'size' ] ) ? sanitize_key( $_REQUEST[ 'size' ] ) : 'tmdb-thumb';
		$desc = isset( $_REQUEST[ 'desc' ] ) ? sanitize_text_field( $_REQUEST[ 'desc' ] ) : array_pop( $filename );

		check_ajax_referer( "media_sideload_image-$post_id", '_wpnonce', false );

		// download and attach, silent fail - need to work on that
		$img = @media_sideload_image( $url, $post_id, $desc );

		$attachments = get_children( array(
			'post_type' => 'attachment',
			'post_mime_type' => 'image',
			'post_parent' => $post_id,
			'orderby' => 'post_date',
			'order' => 'DESC'
		) );

		$latest = array_shift( $attachments );

		// add attachment id as html attrib for custom js
		echo '<span class="img-wrap">'. wp_get_attachment_image( $latest->ID, $size, false, array( 'data-attachment-id' => $latest->ID ) ) .'</span>';
		die();

	}


	// rememeber which images we got
	function ajax_finished_download() {

		$post_id = intval( $_POST[ 'post_id' ] );
		if ( $post_id ) {

			$images = array_filter( $_POST[ 'images' ], function( $id ) {
				return intval( $id ) > 0;
			} );

			// store attachment IDs of images we downloaded
			if ( ! empty( $images ) )
				update_post_meta( $post_id, 'tmdb_movie_images', $images );

		}

	}


	function save( $post_id, $post ) {

		if ( $this->get( 'done_save' ) )
			return $post_id;

		$this->set( 'done_save', true );

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return $post_id;

		if ( ! current_user_can( 'edit_posts' ) )
			return $post_id;

		// tmdb save button clicked
		if ( isset( $_POST[ 'tmdb' ] ) ) {

			$movie_search = sanitize_text_field( $_POST[ 'tmdb_movie_search' ] );

			$movies = $this->a2o( $this->api->searchMovie( $movie_search ) );

			delete_post_meta( $post_id, 'tmdb_movie_id' );
			delete_post_meta( $post_id, 'tmdb_movie_data' );
			delete_post_meta( $post_id, 'tmdb_movie_trailer' );
			delete_post_meta( $post_id, 'tmdb_movie_trailers' );
			delete_post_meta( $post_id, 'tmdb_movie_country' );
			delete_post_meta( $post_id, 'tmdb_movie_certificate' );
			delete_post_meta( $post_id, 'tmdb_movie_release_date' );
			delete_post_meta( $post_id, 'tmdb_movie_images' );

			if ( ! is_wp_error( $movies ) ) {
				update_post_meta( $post_id, 'tmdb_movie_search', $movie_search );
				update_post_meta( $post_id, 'tmdb_movies', (object)$movies );
			} else {
				// show error
			}

			do_action( 'save_movie_on_search', $post_id, $post, $movie_search, $movies );

		}

		// tmdb next button clicked
		if ( isset( $_POST[ 'tmdb_select' ] ) ) {

			$movie_id = intval( $_POST[ 'tmdb_movie' ] );
			$movie_data = $this->a2o( $this->api->getMovie( $movie_id, array( 'casts', 'images', 'keywords', 'releases', 'trailers' ) ) );

			update_post_meta( $post_id, 'tmdb_movie_id', $movie_id );
			update_post_meta( $post_id, 'tmdb_movie_data', $movie_data );

			// sometimes the API goes 'meh'
			try {

				// store youtube trailers if available
				foreach( $movie_data->trailers->youtube as $youtube )
					add_post_meta( $post_id, 'tmdb_movie_trailers', $youtube );

				// prep taxonomy terms
				$genres = array();
				foreach( $movie_data->genres as $genre )
					$genres[] = $genre->name;

				$directors = array();
				$writers = array();
				$actors = array();
				foreach( $movie_data->casts->cast as $actor ) {
					$actors[] = $actor->name;

					// store tmdb person ID by their term id
					if ( ! empty( $taxonomy ) ) {
						$person = wp_create_term( $actor->name, 'movie_actor' );
						add_option( 'tmdb_person_' . $person, $actor->id );
					}
				}

				foreach( $movie_data->casts->crew as $crew ) {
					$taxonomy = '';
					if ( stristr( $crew->department, 'directing' ) ) {
						$directors[] = $crew->name;
						$taxonomy = 'movie_director';
					}
					if ( stristr( $crew->department, 'writing' ) ) {
						$writers[] = $crew->name;
						$taxonomy = 'movie_writer';
					}

					// store tmdb person ID by their term id
					if ( ! empty( $taxonomy ) ) {
						$person = wp_create_term( $crew->name, $taxonomy );
						add_option( 'tmdb_person_' . $person, $crew->id );
					}
				}


				// set terms
				wp_set_post_terms( $post_id, implode( ', ', $actors ), 'movie_actor', false );
				wp_set_post_terms( $post_id, implode( ', ', $genres ), 'movie_genre', false );
				wp_set_post_terms( $post_id, implode( ', ', $directors ), 'movie_director', false );
				wp_set_post_terms( $post_id, implode( ', ', $writers ), 'movie_writer', false );

				// set post content to overview
				$post->post_content = wpautop( wptexturize( $movie_data->overview ) );
				wp_update_post( $post );

			} catch( Exception $error ) {

				// this will take us back to the movies list at least
				delete_post_meta( $post_id, 'tmdb_movie_id' );
				delete_post_meta( $post_id, 'tmdb_movie_data' );
				delete_post_meta( $post_id, 'tmdb_movie_trailer' );
				delete_post_meta( $post_id, 'tmdb_movie_trailers' );
				delete_post_meta( $post_id, 'tmdb_movie_country' );
				delete_post_meta( $post_id, 'tmdb_movie_certificate' );
				delete_post_meta( $post_id, 'tmdb_movie_release_date' );
				delete_post_meta( $post_id, 'tmdb_movie_images' );

			}

			do_action( 'save_movie_on_select', $post_id, $post );

		}

		// Select country to get release info to set certificate
		if ( isset( $_POST[ 'tmdb_release_select' ] ) ) {
			$movie_data = get_post_meta( $post_id, 'tmdb_movie_data', true );
			$certificate = $release_date = '';
			foreach( $movie_data->releases->countries as $release ) {
				if ( $release->iso_3166_1 == $_POST[ 'tmdb_release_country' ] ) {
					$certificate = $release->certification;
					$release_date = $release->release_date;
				}
			}
			wp_set_post_terms( $post_id, $certificate, 'movie_certificate', false );
			update_post_meta( $post_id, 'tmdb_movie_certificate', $certificate );
			update_post_meta( $post_id, 'tmdb_movie_release_date', $release_date );
			update_post_meta( $post_id, 'tmdb_movie_country', $_POST[ 'tmdb_release_country' ] );
		}

		// Trailers
		if ( isset( $_POST[ 'tmdb_movie_trailer_select_submit' ] ) )
			update_post_meta( $post_id, 'tmdb_movie_trailer', esc_url( $_POST[ 'tmdb_movie_trailer_select' ] ) );
		elseif ( isset( $_POST[ 'tmdb_movie_trailer' ] ) )
			update_post_meta( $post_id, 'tmdb_movie_trailer', esc_url( $_POST[ 'tmdb_movie_trailer' ] ) );

		do_action( 'save_movie', $post_id, $post );

	}


	// post class
	function post_class( $classes, $class, $post_id ) {
		$post = get_post( $post_id );

		if ( ! post_type_supports( $post->post_type, 'movie' ) )
			return $classes;

		// Certificate
		if ( is_object_in_taxonomy( $post->post_type, 'movie_certificate' ) ) {
			foreach ( (array) get_the_terms( $post->ID, 'movie_certificate' ) as $tag ) {
				if ( empty( $tag->slug ) )
					continue;
				$classes[] = 'certificate-' . sanitize_html_class( $tag->slug, $tag->term_id );
			}
		}

		// Genres
		if ( is_object_in_taxonomy( $post->post_type, 'movie_genre' ) ) {
			foreach ( (array) get_the_terms( $post->ID, 'movie_genre' ) as $tag ) {
				if ( empty( $tag->slug ) )
					continue;
				$classes[] = 'genre-' . sanitize_html_class( $tag->slug, $tag->term_id );
			}
		}

		return $classes;
	}

	/**
	 * Get data from the movie database XML response
	 *
	 * @param String $key     The object property to return a value for eg. trailer, budget, url etc...
	 * @param Integer $post_id A valid post id to get the data from
	 *
	 * @return Mixed    returns the string value or object
	 */
	public function movie_info( $key = '', $post_id = 0 ) {
		global $post;

		if ( ! $post_id && isset( $post->ID ) )
			$post_id = $post->ID;

		if ( ! $post_id )
			return;

		$movie_data = get_post_meta( $post_id, 'tmdb_movie_data', true );

		if ( is_object( $movie_data ) && ! empty( $movie_data ) ) {

			if ( ! empty( $key ) && property_exists( $movie_data, $key ) )
				return $movie_data->$key;

			return $movie_data;
		}

		return false;
	}


	function iso_3166_1_to_text( $code ) {
		$codes = array(
		'AF' =>	'AFGHANISTAN',
		'AX' =>	'ÅLAND ISLANDS',
		'AL' =>	'ALBANIA',
		'DZ' =>	'ALGERIA',
		'AS' =>	'AMERICAN SAMOA',
		'AD' =>	'ANDORRA',
		'AO' =>	'ANGOLA',
		'AI' =>	'ANGUILLA',
		'AQ' =>	'ANTARCTICA',
		'AG' =>	'ANTIGUA AND BARBUDA',
		'AR' =>	'ARGENTINA',
		'AM' =>	'ARMENIA',
		'AW' =>	'ARUBA',
		'AU' =>	'AUSTRALIA',
		'AT' =>	'AUSTRIA',
		'AZ' =>	'AZERBAIJAN',
		'BS' =>	'BAHAMAS',
		'BH' =>	'BAHRAIN',
		'BD' =>	'BANGLADESH',
		'BB' =>	'BARBADOS',
		'BY' =>	'BELARUS',
		'BE' =>	'BELGIUM',
		'BZ' =>	'BELIZE',
		'BJ' =>	'BENIN',
		'BM' =>	'BERMUDA',
		'BT' =>	'BHUTAN',
		'BO' =>	'BOLIVIA, PLURINATIONAL STATE OF',
		'BQ' =>	'BONAIRE, SINT EUSTATIUS AND SABA',
		'BA' =>	'BOSNIA AND HERZEGOVINA',
		'BW' =>	'BOTSWANA',
		'BV' =>	'BOUVET ISLAND',
		'BR' =>	'BRAZIL',
		'IO' =>	'BRITISH INDIAN OCEAN TERRITORY',
		'BN' =>	'BRUNEI DARUSSALAM',
		'BG' =>	'BULGARIA',
		'BF' =>	'BURKINA FASO',
		'BI' =>	'BURUNDI',
		'KH' =>	'CAMBODIA',
		'CM' =>	'CAMEROON',
		'CA' =>	'CANADA',
		'CV' =>	'CAPE VERDE',
		'KY' =>	'CAYMAN ISLANDS',
		'CF' =>	'CENTRAL AFRICAN REPUBLIC',
		'TD' =>	'CHAD',
		'CL' =>	'CHILE',
		'CN' =>	'CHINA',
		'CX' =>	'CHRISTMAS ISLAND',
		'CC' =>	'COCOS (KEELING) ISLANDS',
		'CO' =>	'COLOMBIA',
		'KM' =>	'COMOROS',
		'CG' =>	'CONGO',
		'CD' =>	'CONGO, THE DEMOCRATIC REPUBLIC OF THE',
		'CK' =>	'COOK ISLANDS',
		'CR' =>	'COSTA RICA',
		'CI' =>	'CÔTE D\'IVOIRE',
		'HR' =>	'CROATIA',
		'CU' =>	'CUBA',
		'CW' =>	'CURAÇAO',
		'CY' =>	'CYPRUS',
		'CZ' =>	'CZECH REPUBLIC',
		'DK' =>	'DENMARK',
		'DJ' =>	'DJIBOUTI',
		'DM' =>	'DOMINICA',
		'DO' =>	'DOMINICAN REPUBLIC',
		'EC' =>	'ECUADOR',
		'EG' =>	'EGYPT',
		'SV' =>	'EL SALVADOR',
		'GQ' =>	'EQUATORIAL GUINEA',
		'ER' =>	'ERITREA',
		'EE' =>	'ESTONIA',
		'ET' =>	'ETHIOPIA',
		'FK' =>	'FALKLAND ISLANDS (MALVINAS)',
		'FO' =>	'FAROE ISLANDS',
		'FJ' =>	'FIJI',
		'FI' =>	'FINLAND',
		'FR' =>	'FRANCE',
		'GF' =>	'FRENCH GUIANA',
		'PF' =>	'FRENCH POLYNESIA',
		'TF' =>	'FRENCH SOUTHERN TERRITORIES',
		'GA' =>	'GABON',
		'GM' =>	'GAMBIA',
		'GE' =>	'GEORGIA',
		'DE' =>	'GERMANY',
		'GH' =>	'GHANA',
		'GI' =>	'GIBRALTAR',
		'GR' =>	'GREECE',
		'GL' =>	'GREENLAND',
		'GD' =>	'GRENADA',
		'GP' =>	'GUADELOUPE',
		'GU' =>	'GUAM',
		'GT' =>	'GUATEMALA',
		'GG' =>	'GUERNSEY',
		'GN' =>	'GUINEA',
		'GW' =>	'GUINEA-BISSAU',
		'GY' =>	'GUYANA',
		'HT' =>	'HAITI',
		'HM' =>	'HEARD ISLAND AND MCDONALD ISLANDS',
		'VA' =>	'HOLY SEE (VATICAN CITY STATE)',
		'HN' =>	'HONDURAS',
		'HK' =>	'HONG KONG',
		'HU' =>	'HUNGARY',
		'IS' =>	'ICELAND',
		'IN' =>	'INDIA',
		'ID' =>	'INDONESIA',
		'IR' =>	'IRAN, ISLAMIC REPUBLIC OF',
		'IQ' =>	'IRAQ',
		'IE' =>	'IRELAND',
		'IM' =>	'ISLE OF MAN',
		'IL' =>	'ISRAEL',
		'IT' =>	'ITALY',
		'JM' =>	'JAMAICA',
		'JP' =>	'JAPAN',
		'JE' =>	'JERSEY',
		'JO' =>	'JORDAN',
		'KZ' =>	'KAZAKHSTAN',
		'KE' =>	'KENYA',
		'KI' =>	'KIRIBATI',
		'KP' =>	'KOREA, DEMOCRATIC PEOPLE\'S REPUBLIC OF',
		'KR' =>	'KOREA, REPUBLIC OF',
		'KW' =>	'KUWAIT',
		'KG' =>	'KYRGYZSTAN',
		'LA' =>	'LAO PEOPLE\'S DEMOCRATIC REPUBLIC',
		'LV' =>	'LATVIA',
		'LB' =>	'LEBANON',
		'LS' =>	'LESOTHO',
		'LR' =>	'LIBERIA',
		'LY' =>	'LIBYA',
		'LI' =>	'LIECHTENSTEIN',
		'LT' =>	'LITHUANIA',
		'LU' =>	'LUXEMBOURG',
		'MO' =>	'MACAO',
		'MK' =>	'MACEDONIA, THE FORMER YUGOSLAV REPUBLIC OF',
		'MG' =>	'MADAGASCAR',
		'MW' =>	'MALAWI',
		'MY' =>	'MALAYSIA',
		'MV' =>	'MALDIVES',
		'ML' =>	'MALI',
		'MT' =>	'MALTA',
		'MH' =>	'MARSHALL ISLANDS',
		'MQ' =>	'MARTINIQUE',
		'MR' =>	'MAURITANIA',
		'MU' =>	'MAURITIUS',
		'YT' =>	'MAYOTTE',
		'MX' =>	'MEXICO',
		'FM' =>	'MICRONESIA, FEDERATED STATES OF',
		'MD' =>	'MOLDOVA, REPUBLIC OF',
		'MC' =>	'MONACO',
		'MN' =>	'MONGOLIA',
		'ME' =>	'MONTENEGRO',
		'MS' =>	'MONTSERRAT',
		'MA' =>	'MOROCCO',
		'MZ' =>	'MOZAMBIQUE',
		'MM' =>	'MYANMAR',
		'NA' =>	'NAMIBIA',
		'NR' =>	'NAURU',
		'NP' =>	'NEPAL',
		'NL' =>	'NETHERLANDS',
		'NC' =>	'NEW CALEDONIA',
		'NZ' =>	'NEW ZEALAND',
		'NI' =>	'NICARAGUA',
		'NE' =>	'NIGER',
		'NG' =>	'NIGERIA',
		'NU' =>	'NIUE',
		'NF' =>	'NORFOLK ISLAND',
		'MP' =>	'NORTHERN MARIANA ISLANDS',
		'NO' =>	'NORWAY',
		'OM' =>	'OMAN',
		'PK' =>	'PAKISTAN',
		'PW' =>	'PALAU',
		'PS' =>	'PALESTINIAN TERRITORY, OCCUPIED',
		'PA' =>	'PANAMA',
		'PG' =>	'PAPUA NEW GUINEA',
		'PY' =>	'PARAGUAY',
		'PE' =>	'PERU',
		'PH' =>	'PHILIPPINES',
		'PN' =>	'PITCAIRN',
		'PL' =>	'POLAND',
		'PT' =>	'PORTUGAL',
		'PR' =>	'PUERTO RICO',
		'QA' =>	'QATAR',
		'RE' =>	'RÉUNION',
		'RO' =>	'ROMANIA',
		'RU' =>	'RUSSIAN FEDERATION',
		'RW' =>	'RWANDA',
		'BL' =>	'SAINT BARTHÉLEMY',
		'SH' =>	'SAINT HELENA, ASCENSION AND TRISTAN DA CUNHA',
		'KN' =>	'SAINT KITTS AND NEVIS',
		'LC' =>	'SAINT LUCIA',
		'MF' =>	'SAINT MARTIN (FRENCH PART)',
		'PM' =>	'SAINT PIERRE AND MIQUELON',
		'VC' =>	'SAINT VINCENT AND THE GRENADINES',
		'WS' =>	'SAMOA',
		'SM' =>	'SAN MARINO',
		'ST' =>	'SAO TOME AND PRINCIPE',
		'SA' =>	'SAUDI ARABIA',
		'SN' =>	'SENEGAL',
		'RS' =>	'SERBIA',
		'SC' =>	'SEYCHELLES',
		'SL' =>	'SIERRA LEONE',
		'SG' =>	'SINGAPORE',
		'SX' =>	'SINT MAARTEN (DUTCH PART)',
		'SK' =>	'SLOVAKIA',
		'SI' =>	'SLOVENIA',
		'SB' =>	'SOLOMON ISLANDS',
		'SO' =>	'SOMALIA',
		'ZA' =>	'SOUTH AFRICA',
		'GS' =>	'SOUTH GEORGIA AND THE SOUTH SANDWICH ISLANDS',
		'SS' =>	'SOUTH SUDAN',
		'ES' =>	'SPAIN',
		'LK' =>	'SRI LANKA',
		'SD' =>	'SUDAN',
		'SR' =>	'SURINAME',
		'SJ' =>	'SVALBARD AND JAN MAYEN',
		'SZ' =>	'SWAZILAND',
		'SE' =>	'SWEDEN',
		'CH' =>	'SWITZERLAND',
		'SY' =>	'SYRIAN ARAB REPUBLIC',
		'TW' =>	'TAIWAN, PROVINCE OF CHINA',
		'TJ' =>	'TAJIKISTAN',
		'TZ' =>	'TANZANIA, UNITED REPUBLIC OF',
		'TH' =>	'THAILAND',
		'TL' =>	'TIMOR-LESTE',
		'TG' =>	'TOGO',
		'TK' =>	'TOKELAU',
		'TO' =>	'TONGA',
		'TT' =>	'TRINIDAD AND TOBAGO',
		'TN' =>	'TUNISIA',
		'TR' =>	'TURKEY',
		'TM' =>	'TURKMENISTAN',
		'TC' =>	'TURKS AND CAICOS ISLANDS',
		'TV' =>	'TUVALU',
		'UG' =>	'UGANDA',
		'UA' =>	'UKRAINE',
		'AE' =>	'UNITED ARAB EMIRATES',
		'GB' =>	'UNITED KINGDOM',
		'US' =>	'UNITED STATES',
		'UM' =>	'UNITED STATES MINOR OUTLYING ISLANDS',
		'UY' =>	'URUGUAY',
		'UZ' =>	'UZBEKISTAN',
		'VU' =>	'VANUATU',
		'VE' =>	'VENEZUELA, BOLIVARIAN REPUBLIC OF',
		'VN' =>	'VIET NAM',
		'VG' =>	'VIRGIN ISLANDS, BRITISH',
		'VI' =>	'VIRGIN ISLANDS, U.S.',
		'WF' =>	'WALLIS AND FUTUNA',
		'EH' =>	'WESTERN SAHARA',
		'YE' =>	'YEMEN',
		'ZM' =>	'ZAMBIA',
		'ZW' =>	'ZIMBABWE'
		);

		if ( array_key_exists( $code, $codes ) )
			return $codes[ $code ];
		return false;


		// Locale to country code ISO 639_9 <-> 3661_1

		//Afrikaans
		//
		//af
		//
		//Icelandic
		//
		//is
		//
		//Afrikaans - South Africa
		//
		//af-ZA
		//
		//Icelandic - Iceland
		//
		//is-IS
		//
		//Albanian
		//
		//sq
		//
		//Indonesian
		//
		//id
		//
		//Albanian - Albania
		//
		//sq-AL
		//
		//Indonesian - Indonesia
		//
		//id-ID
		//
		//Arabic
		//
		//ar
		//
		//Italian
		//
		//it
		//
		//Arabic - Algeria
		//
		//ar-DZ
		//
		//Italian - Italy
		//
		//it-IT
		//
		//Arabic – Bahrain
		//
		//ar-BH
		//
		//Italian - Switzerland
		//
		//it-CH
		//
		//Arabic – Egypt
		//
		//ar-EG
		//
		//Japanese
		//
		//ja
		//
		//Arabic – Iraq
		//
		//ar-IQ
		//
		//Japanese - Japan
		//
		//ja-JP
		//
		//Arabic – Jordan
		//
		//ar-JO
		//
		//Kannada
		//
		//kn
		//
		//Arabic – Kuwait
		//
		//ar-KW
		//
		//Kannada - India
		//
		//kn-IN
		//
		//Arabic – Lebanon
		//
		//ar-LB
		//
		//Kazakh
		//
		//kk
		//
		//Arabic – Libya
		//
		//ar-LY
		//
		//Kazakh - Kazakhstan
		//
		//kk-KZ
		//
		//Arabic - Morocco
		//
		//ar-MA
		//
		//Korean
		//
		//ko
		//
		//Arabic - Oman
		//
		//ar-OM
		//
		//Korean - Korea
		//
		//ko-KR
		//
		//Arabic - Qatar
		//
		//ar-QA
		//
		//Kyrgyz
		//
		//ky
		//
		//Arabic - Saudi Arabia
		//
		//ar-SA
		//
		//Kyrgyz - Kyrgyzstan
		//
		//ky-KG
		//
		//Arabic - Syria
		//
		//ar-SY
		//
		//Latvian
		//
		//lv
		//
		//Arabic - Tunisia
		//
		//ar-TN
		//
		//Latvian - Latvia
		//
		//lv-LV
		//
		//Arabic - United Arab Emirates
		//
		//ar-AE
		//
		//Lithuanian
		//
		//lt
		//
		//Arabic - Yemen
		//
		//ar-YE
		//
		//Lithuanian - Lithuania
		//
		//lt-LT
		//
		//Armenian
		//
		//hy
		//
		//Macedonian
		//
		//mk
		//
		//Armenian - Armenia
		//
		//hy-AM
		//
		//Macedonian - Former Yugoslav Republic of Macedonia
		//
		//mk-MK
		//
		//Azeri
		//
		//az
		//
		//Malay
		//
		//ms
		//
		//Azeri - Azerbaijan
		//
		//az-AZ
		//
		//Malay - Brunei
		//
		//ms-BN
		//
		//Basque
		//
		//eu
		//
		//Malay - Malaysia
		//
		//ms-MY
		//
		//Basque - Basque
		//
		//eu-ES
		//
		//Marathi
		//
		//mr
		//
		//Belarusian
		//
		//be
		//
		//Marathi - India
		//
		//mr-IN
		//
		//Belarusian - Belarus
		//
		//be-BY
		//
		//Mongolian
		//
		//mn
		//
		//Bulgarian
		//
		//bg
		//
		//Mongolian - Mongolia
		//
		//mn-MN
		//
		//Bulgarian - Bulgaria
		//
		//bg-BG
		//
		//Norwegian
		//
		//no
		//
		//Catalan
		//
		//ca
		//
		//Norwegian (Bokmål) - Norway
		//
		//nb-NO
		//
		//Catalan - Spain
		//
		//ca-ES
		//
		//Norwegian (Nynorsk) - Norway
		//
		//nn-NO
		//
		//Chinese
		//
		//zh
		//
		//Polish
		//
		//pl
		//
		//Chinese - Hong Kong SAR
		//
		//zh-HK
		//
		//Polish - Poland
		//
		//pl-PL
		//
		//Chinese - Macao SAR
		//
		//zh-MO
		//
		//Portuguese
		//
		//pt
		//
		//Chinese - China (Simplified Chinese)
		//
		//zh-CN
		//
		//Portuguese - Brazil
		//
		//pt-BR
		//
		//Chinese - Singapore
		//
		//zh-SG
		//
		//Portuguese - Portugal
		//
		//pt-PT
		//
		//Chinese - Taiwan (Traditional Chinese)
		//
		//zh-TW
		//
		//Punjabi
		//
		//pa
		//
		//Croatian
		//
		//hr
		//
		//Punjabi - India
		//
		//pa-IN
		//
		//Croatian - Croatia
		//
		//hr-HR
		//
		//Romanian
		//
		//ro
		//
		//Czech
		//
		//cs
		//
		//Romanian - Romania
		//
		//ro-RO
		//
		//Czech - Czech Republic
		//
		//cs-CZ
		//
		//Russian
		//
		//ru
		//
		//Danish
		//
		//da
		//
		//Russian - Russia
		//
		//ru-RU
		//
		//Danish - Denmark
		//
		//da-DK
		//
		//Sanskrit
		//
		//sa
		//
		//Dutch
		//
		//nl
		//
		//Sanskrit - India
		//
		//sa-IN
		//
		//Dutch - Belgium
		//
		//nl-BE
		//
		//Serbian
		//
		//sr
		//
		//Dutch - The Netherlands
		//
		//nl-NL
		//
		//Serbian - Serbia
		//
		//sr-SP
		//
		//English
		//
		//en
		//
		//Slovak
		//
		//sk
		//
		//English - Australia
		//
		//en-AU
		//
		//Slovak - Slovakia
		//
		//sk-SK
		//
		//English - Belize
		//
		//en-BZ
		//
		//Slovenian
		//
		//sl
		//
		//English - Canada
		//
		//en-CA
		//
		//Slovenian - Slovenia
		//
		//sl-SI
		//
		//English - Caribbean
		//
		//en-CB
		//
		//Spanish
		//
		//es
		//
		//English - Ireland
		//
		//en-IE
		//
		//Spanish - Argentina
		//
		//es-AR
		//
		//English - Jamaica
		//
		//en-JM
		//
		//Spanish - Bolivia
		//
		//es-BO
		//
		//English - New Zealand
		//
		//en-NZ
		//
		//Spanish - Chile
		//
		//es-CL
		//
		//English - Philippines
		//
		//en-PH
		//
		//Spanish - Colombia
		//
		//es-CO
		//
		//English - South Africa
		//
		//en-ZA
		//
		//Spanish - Costa Rica
		//
		//es-CR
		//
		//English - Trinidad and Tobago
		//
		//en-TT
		//
		//Spanish - Dominican Republic
		//
		//es-DO
		//
		//English - United Kingdom
		//
		//en-GB
		//
		//Spanish - Ecuador
		//
		//es-EC
		//
		//English - United States
		//
		//en-US
		//
		//Spanish - El Salvador
		//
		//es-SV
		//
		//English - Zimbabwe
		//
		//en-ZW
		//
		//Spanish - Guatemala
		//
		//es-GT
		//
		//Estonian
		//
		//et
		//
		//Spanish - Honduras
		//
		//es-HN
		//
		//Estonian - Estonia
		//
		//et-EE
		//
		//Spanish - Mexico
		//
		//es-MX
		//
		//Faroese
		//
		//fo
		//
		//Spanish - Nicaragua
		//
		//es-NI
		//
		//Faroese - Faroe Islands
		//
		//fo-FO
		//
		//Spanish - Panama
		//
		//es-PA
		//
		//Farsi
		//
		//fa
		//
		//Spanish - Paraguay
		//
		//es-PY
		//
		//Farsi - Iran
		//
		//fa-IR
		//
		//Spanish - Peru
		//
		//es-PE
		//
		//Finnish
		//
		//fi
		//
		//Spanish - Puerto Rico
		//
		//es-PR
		//
		//Finnish - Finland
		//
		//fi-FI
		//
		//Spanish - Spain
		//
		//es-ES
		//
		//French
		//
		//fr
		//
		//Spanish - Uruguay
		//
		//es-UY
		//
		//French - Belgium
		//
		//fr-BE
		//
		//Spanish - Venezuela
		//
		//es-VE
		//
		//French - Canada
		//
		//fr-CA
		//
		//Swahili
		//
		//sw
		//
		//French - France
		//
		//fr-FR
		//
		//Swahili - Kenya
		//
		//sw-KE
		//
		//French - Luxembourg
		//
		//fr-LU
		//
		//Swedish
		//
		//sv
		//
		//French - Monaco
		//
		//fr-MC
		//
		//Swedish - Finland
		//
		//sv-FI
		//
		//French - Switzerland
		//
		//fr-CH
		//
		//Swedish - Sweden
		//
		//sv-SE
		//
		//Galician
		//
		//gl
		//
		//Tamil
		//
		//ta
		//
		//Galician - Galician
		//
		//gl-ES
		//
		//Tamil - India
		//
		//ta-IN
		//
		//Georgian
		//
		//ka
		//
		//Tatar
		//
		//tt
		//
		//Georgian - Georgia
		//
		//ka-GE
		//
		//Tatar - Russia
		//
		//tt-RU
		//
		//German
		//
		//de
		//
		//Telugu
		//
		//te
		//
		//German - Austria
		//
		//de-AT
		//
		//Telugu - India
		//
		//te-IN
		//
		//German - Germany
		//
		//de-DE
		//
		//Thai
		//
		//th
		//
		//German - Liechtenstein
		//
		//de-LI
		//
		//Thai - Thailand
		//
		//th-TH
		//
		//German - Luxembourg
		//
		//de-LU
		//
		//Turkish
		//
		//tr
		//
		//German - Switzerland
		//
		//de-CH
		//
		//Turkish - Turkey
		//
		//tr-TR
		//
		//Greek
		//
		//el
		//
		//Ukrainian
		//
		//uk
		//
		//Greek - Greece
		//
		//el-GR
		//
		//Ukrainian - Ukraine
		//
		//uk-UA
		//
		//Gujarati
		//
		//gu
		//
		//Urdu
		//
		//ur
		//
		//Gujarati - India
		//
		//gu-IN
		//
		//Urdu - Pakistan
		//
		//ur-PK
		//
		//Hebrew
		//
		//he
		//
		//Uzbek
		//
		//uz
		//
		//Hebrew - Israel
		//
		//he-IL
		//
		//Uzbek - Uzbekistan
		//
		//uz-UZ
		//
		//Hindi
		//
		//hi
		//
		//Vietnamese
		//
		//vi
		//
		//Hindi - India
		//
		//hi-IN
		//
		//Vietnamese - Vietnam
		//
		//vi-VN
		//
		//Hungarian
		//
		//hu
		//
		//
		//
		//
		//
		//Hungarian - Hungary
		//
		//hu-HU


	}

}

if ( ! function_exists( 'tmdb_movie_info' ) ) {

	/**
	* Get data from the movie database XML response
	*
	* @param String $key     The object property to return a value for eg. trailer, budget, url etc...
	* @param Integer $post_id A valid post id to get the data from
	*
	* @return Mixed    returns the string value or object
	*/
	function tmdb_movie_info( $key = '', $post_id = 0 ) {
	   $tmdb = tmdb_api::instance();
	   return $tmdb->movie_info( $key, $post_id );
	}

}

?>
