<?php
/*
Plugin Name: TMDb API
Plugin URI: http://interconnectit.com
Description: This plugin provides a way to use themoviedb.org API within wordpress. It adds a UI for searching a movie and provides a means of inserting trailers or images into posts.
Author: Robert O'Rourke
Version: 0.1
Author URI: http://interconnectit.com
*/

if ( ! defined( 'TMDB_PLUGIN_URL' ) )
	define( 'TMDB_PLUGIN_URL', plugins_url( '', __FILE__ ) );

if ( ! defined( 'TMDB_PLUGIN_BASE' ) )
	define( 'TMDB_PLUGIN_BASE', basename( __FILE__ ) );

if ( ! defined( 'TMDB_PLUGIN_CACHE_TIME' ) )
	define( 'TMDB_PLUGIN_CACHE_TIME', 3600 );

add_image_size( 'tmdb-thumb', 150, 150, false ); // natural size within a box

class tmdb_api {

	var $api_key;
	var $version = 2.1;
	var $lang;
	var $format;
	var $methods;
	var $cache;
	var $token;
	var $session_key;

	function tmdb_api( $format = 'xml', $lang = 'default', $auth = false, $cache = true ) {

		// set language
		if ( $lang == 'default' )
			$this->lang = get_locale();
		else
			$this->lang = $lang;
		$this->lang = str_replace( "_", "-", $this->lang ); // tmdb likes the hyphenated format

		// set API key
		$this->api_key = get_option( 'tmdb_api_key' );

		// set format
		$this->format = $format;

		// caching?
		$this->cache = $cache;

		// check for session key
		if ( apply_filters( 'tmdb_api_auth', $auth ) )
			$this->session_key = get_transient( 'tmdb_session_key' );

	}

	function get( $method, $args, $request = 'get' ) {

		// check method and args
		if ( ! $this->methods( $method ) ) {
			// error
			return false;
		}

		// if we're good to go
		if ( $this->api_key ) {

			// build request URL
			$url = "http://api.themoviedb.org/{$this->version}/{$method}";

			// no auth required
			if ( $request == 'get' ) {
				$url .= "/{$this->lang}/xml/{$this->api_key}";

				// complete the URL
				if ( is_array( $args ) ) {
					$query_args = array();
					foreach( $args as $key => $val )
						$query_args[] = $key . "=" . urlencode( $val );
					$url .= "?" . implode( '&', $query_args );
				} else {
					$url .= '/' . urlencode( "$args" );
				}

			// auth required
			} else {
				// default post arguments
				$args = wp_parse_args( $args, array(
					'api_key' => $this->api_key,
					'session_key' => $this->session_key,
					'type' => $this->format
				) );
			}

			// unique identifier for this response
			$url_hash = 'tmdb_' . md5( $url . serialize( $args ) );

			// check cache
			if ( false === ( $response = get_transient( $url_hash ) ) ) {

				if ( $request == 'get' ) {
					$response = wp_remote_get( $url );
				} else {
					$response = wp_remote_post( $url, array(
						'body' => $args
					) );
				}

				if ( is_wp_error( $response ) )
					return $response;

				$response = $response[ 'body' ];

				// if not an error code set the transient
				$xml = new SimpleXMLElement( $response );
				$json = json_encode( $xml ); // nasty way of getting JSON back until the API encoding bugs are fixed
				$rsp = $xml;

				// cache result
				if ( isset( $rsp->status ) && $rsp->status->code == 1 && $this->cache ) {
					set_transient( $url_hash, $response, TMDB_PLUGIN_CACHE_TIME ); // cache the response for 1 hour
				// or set an error
				} elseif ( isset( $rsp->status ) ) {
					$error = new WP_Error( $rsp->status->code, $this->codes( $rsp->status->code ), $rsp );
				}

			}

			// check response status
			if ( $this->format == 'json' )
				return $json;
			return $response;

		} else {

			// api key error

		}

	}

	function methods( $method = false ) {

		// auth methods
		$methods = array(
			'Auth.getSession',
			'Auth.getToken',

			'Media.addID',
			'Media.getInfo',

			'Movie.addRating',
			'Movie.browse',
			'Movie.getImages',
			'Movie.getInfo',
			'Movie.getLatest',
			'Movie.getTranslations',
			'Movie.getVersion',
			'Movie.imdbLookup',
			'Movie.search',

			'Person.getInfo',
			'Person.getLatest',
			'Person.getVersion',
			'Person.search',

			'Genres.getList'
		);

		if ( $method )
			return in_array( $method, $methods ) ? true : false;

		return $methods;

	}

	// auth functions
	function auth_get_session( $token ) {
		return $this->get( 'Auth.getSession', $token );
	}
	function auth_get_token(  ) {
		return $this->get( 'Auth.getToken' );
	}
	function auth_create_session() {

	}

	// media functions
	function media_add_id( $args ) {
		return $this->get( 'Media.addID', $args, 'post' );
	}
	function media_get_info( $args ) {
		return $this->get( 'Media.getInfo', $args );
	}

	// movie functions
	function movie_add_rating() {

	}
	function movie_browse() {

	}
	function movie_get_images() {

	}
	function movie_get_info( $args ) {
		return $this->get( 'Movie.getInfo', $args );
	}
	function movie_get_latest() {

	}
	function movie_get_translations() {

	}
	function movie_get_version() {

	}
	function movie_imdb_lookup() {

	}
	function movie_search( $args ) {
		return $this->get( 'Movie.search', $args );
	}

	function codes( $code = false ) {
		$codes = array(
			1 	=> __( 'Success.' ),
			2 	=> __( 'Invalid service - This service does not exist.' ),
			3 	=> __( 'Authentication Failed - You do not have permissions to access the service.' ),
			4 	=> __( 'Invalid format - This service doesn\'t exist in that format.' ),
			5 	=> __( 'Invalid parameters - Your request parameters are incorrect.' ),
			6 	=> __( 'Invalid pre-requisite id - The pre-requisite id is invalid or not found.' ),
			7 	=> __( 'Invalid API key - You must be granted a valid key.' ),
			8 	=> __( 'Duplicate entry - The data you tried to submit already exists.' ),
			9 	=> __( 'Service Offline - This service is temporarily offline. Try again later.' ),
			10 	=> __( 'Suspended API key - Access to your account has been suspended, contact TMDb.' ),
			11 	=> __( 'Internal error - Something went wrong. Contact TMDb.' ),
			12 	=> __( 'The item/record was updated successfully.' ),
			13 	=> __( 'The item/record was deleted successfully.' ),
			14 	=> __( 'Authentication Failed.' ),
			15 	=> __( 'Failed.' ),
			16 	=> __( 'Device Denied.' ),
			17 	=> __( 'Session Denied.' )
		);

		if ( $code && is_int( $code ) && key_exists( $code, $codes ) )
			return $codes[ $code ];

		return $codes;
	}


	function get_info( $key = '', $post_id = 0 ) {
		global $post;

		if ( ! $post_id && isset( $post->ID ) )
			$post_id = $post->ID;

		if ( ! $post_id )
			return;

		$movie_data = get_post_meta( $post_id, 'tmdb_movie_data', true );
		if ( is_string( $movie_data ) && ! empty( $movie_data ) ) {
			$movie_data = new SimpleXMLElement( $movie_data );

			$movie_data = $movie_data->movies->movie;

			if ( ! empty( $key ) && property_exists( $movie_data, $key ) )
				return $movie_data->$key;

			return $movie_data;
		}

		return false;
	}

}

// instantiate
$tmdb_api = new tmdb_api();
// easy access
function tmdb_api() {
	global $tmdb_api;
	return $tmdb_api;
}

// TMDb API settings
add_action( 'admin_init', 'tmdb_admin_init' );
function tmdb_admin_init() {

	// scripts and styles
	wp_enqueue_style( 'tmdb', TMDB_PLUGIN_URL . '/css/admin.css' );

	// register settings
	add_settings_section( 'tmdb', __( 'The Movie Database&trade;' ), 'tmdb_settings', 'media' );
	register_setting( 'media', 'tmdb_api_key', 'tmdb_test_key' );
	add_settings_field( 'tmdb_api_key', __('API Key'), 'tmdb_api_key_field', 'media', 'tmdb' );

	// add meta box to post types
	foreach( get_post_types( array( 'show_ui' => true ) ) as $post_type ) {
		if ( ! post_type_supports( $post_type, 'movie' ) ) continue;
		// meta box to get movie info and images etc...
		add_meta_box( 'tmdb', __( 'Get movie data' ), 'tmdb_get_movie_data_box', $post_type, 'normal', 'high' );
	}

}

function tmdb_settings() { ?>
	<p id="tmdb-intro"><?php printf( __( 'You\'ll need to request an API key from The Movie Database. Sign up for an account %s and then click the "Want to generate an API key?" link under Account Settings.' ), '<a href="http://www.themoviedb.org/account/signup">' . __( 'here' ) . '</a>' ); ?></p>
	<?php
}

function tmdb_api_key_field() { ?>
	<input class="regular-text code" type="text" name="tmdb_api_key" value="<?php esc_attr_e( get_option( 'tmdb_api_key' ) ); ?>" />
	<?php
}

// test and save
function tmdb_test_key( $api_key ) {

	// try new api key
	tmdb_api()->api_key = $api_key;
	$test = json_decode( tmdb_api()->movie_search( 'Orgazmo' ) );

	if ( ! empty( $api_key ) && is_object( $test ) && $test->status_code != 1 ) {
		add_settings_error( 'tmdb_api_key', $test->status_code, $test->status_message );
		delete_option( 'tmdb_valid_key' );
	} else {
		if ( ! get_option( 'tmdb_valid_key', false ) )
			add_option( 'tmdb_valid_key', true );
		update_option( 'tmdb_valid_key', true );
	}

	return sanitize_key( $api_key );
}

// plugin settings link
add_filter( 'plugin_action_links', 'tmdb_add_settings_link', 10, 2 );
function tmdb_add_settings_link( $links, $file ) {
	if ( $file == plugin_basename( __FILE__ ) )
		array_unshift( $links , '<a href="' . admin_url( 'options-media.php#tmdb-intro' ) . '">' . __( "Settings" ) . '</a>' );
	return $links;
}

register_activation_hook( __FILE__, 'tmdb_activate' );
function tmdb_activate() {

}

// create movies post type and taxonomies - movie can be deregistered
add_action( 'init', 'tmdb_init' );
function tmdb_init() {

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



function tmdb_get_movie_data_box( $post ) {

	$movie_search = get_post_meta( $post->ID, 'tmdb_movie_search', true );
	$movies = get_post_meta( $post->ID, 'tmdb_movies', true );
	$movie_id = intval( get_post_meta( $post->ID, 'tmdb_movie_id', true ) );
	$movie_data = get_post_meta( $post->ID, 'tmdb_movie_data', true );
	$movie_trailer = get_post_meta( $post->ID, 'tmdb_movie_trailer', true );

	?>
	<p><?php _e( 'Enter the movie title and click go to grab images and info.' ); ?></p>
	<p><input type="text" size="60" name="tmdb_movie_search" value="<?php esc_attr_e( $movie_search ); ?>" /> <input type="submit" class="button" name="tmdb" value="<?php _e( 'Search movie database' ); ?>" /></p><?php
	if ( empty( $post->post_title ) ) { ?>
	<p><?php _e( 'You need to enter a title for the post before you can search the movie database.' ); ?></p><?php
	}

	if ( $movies && ! $movie_id ) {
		$movies = new SimpleXMLElement( $movies );
		$n = 0; ?>
	<p><?php _e( 'Select the movie you want to attach to this post.' ); ?></p>
	<ul>
		<?php foreach( $movies->movies->movie as $movie ) { ?>
			<li><label><input <?php if ( $n == 0 ) echo ' checked="checked"'; ?> type="radio" name="tmdb_movie" value="<?php esc_attr_e( $movie->id ) ?>" /> <?php esc_html_e( $movie->name ); ?>, <?php echo date( "Y", strtotime( $movie->released ) ); ?></label></li>
		<?php $n++; } ?>
	</ul>
	<input class="button" type="submit" name="tmdb_select" value="<?php _e( 'Select movie &raquo;' ); ?>" />
	<?php
	}

	if ( $movie_data ) {

		$movie_data = new SimpleXMLElement( $movie_data );

		$posters = array();
		$backdrops = array();
		foreach( $movie_data->movies->movie->images->image as $image ) {
			if ( $image->attributes()->size != 'original' ) continue;

			if ( $image->attributes()->type == 'poster' )
				$posters[] = $image->attributes()->url;

			if ( $image->attributes()->type == 'backdrop' )
				$backdrops[] = $image->attributes()->url;
		}

		?>

		<h4>Movie selected: <?php esc_html_e( $movie_data->movies->movie->name ); ?></h4>
		<p>You can search again to find new movie data.</p>

		<div class="tmdb-images-wrap">
			<h4>Images:</h4>
			<p id="tmdb-get-images-wrap">
				<?php printf( __( 'There are %d poster images and %d backdrops for this movie' ), count( $posters ), count( $backdrops ) ); ?>
				<input id="tmdb-get-images" class="button" type="submit" name="tmdb_get_images" value="<?php _e( 'Grab images' ); ?>" />
			</p>
			<p><?php _e( 'Once the images have been downloaded you can set one as your featured image or insert them into the post as you want.' ); ?></p>
			<div id="tmdb-images"></div>
		</div>
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
						if ( num == images.length - 1 ) {
							$( '#tmdb-get-images-wrap' ).remove();
							getting_images = false;
						} else {
							num++;
							get_next_image();
						}
					} );
				}

				// prevent user navigating away?
				$( document ).bind( 'click.stayhere', function(e) {
					if ( getting_images ) {

					}
				} );
			})(jQuery);
		</script>

		<?php

	}

	?>
		<div id="tmdb-trailer-wrap">
			<p>
				<label for="tmdb-movie-trailer"><?php _e( 'Movie trailer link' ); ?></label>
				<input class="widefat" type="text" name="tmdb_movie_trailer" id="tmdb-movie-trailer" value="<?php esc_attr_e( $movie_trailer ); ?>" />
			</p>
			<div class="tmdb-trailer">
			<?php
				if ( ! empty( $movie_trailer ) )
					echo wp_oembed_get( $movie_trailer );
			?>
			</div>
		</div>
	<?php
}

// ajax upload from url
add_action( 'wp_ajax_media_sideload_image', 'ajax_media_sideload_image' );
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

// use a one shot class to save as we're calling save_post action within it from update_post
class tmdb_save_post {

	var $done = false;

	function tmdb_save_post() {
		add_action( 'save_post', array( &$this, 'save' ), 10, 2 );
	}

	function save( $post_id, $post ) {
		global $tmdb_api;

		if ( $this->done )
			return $post_id;

		$this->done = true;

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return $post_id;

		if ( ! current_user_can( 'edit_posts' ) )
			return $post_id;

		// tmdb save button clicked
		if ( isset( $_POST[ 'tmdb' ] ) ) {

			$movie_search = sanitize_text_field( $_POST[ 'tmdb_movie_search' ] );
			$movies = $tmdb_api->movie_search( $movie_search );

			delete_post_meta( $post_id, 'tmdb_movie_id' );
			delete_post_meta( $post_id, 'tmdb_movie_data' );
			delete_post_meta( $post_id, 'tmdb_movie_trailer' );

			if ( ! is_wp_error( $movies ) ) {
				update_post_meta( $post_id, 'tmdb_movie_search', $movie_search );
				update_post_meta( $post_id, 'tmdb_movies', $movies );
			} else {
				// show error
			}

			do_action( 'save_movie_on_search', $post_id, $post, $movie_search, $movies );

		}

		// tmdb next button clicked
		if ( isset( $_POST[ 'tmdb_select' ] ) ) {

			$movie_id = intval( $_POST[ 'tmdb_movie' ] );
			$movie_data = $tmdb_api->movie_get_info( $movie_id );

			update_post_meta( $post_id, 'tmdb_movie_id', $movie_id );
			update_post_meta( $post_id, 'tmdb_movie_data', $movie_data );

			// sometimes the API goes 'meh'
			try {

				$movie_data = new SimpleXMLElement( $movie_data );

				update_post_meta( $post_id, 'tmdb_movie_trailer', (string)$movie_data->movies->movie->trailer );

				// prep taxonomy terms
				$genres = array();
				foreach( $movie_data->movies->movie->categories->category as $genre )
					$genres[] = $genre->attributes()->name;

				$directors = array();
				$writers = array();
				$actors = array();
				foreach( $movie_data->movies->movie->cast->person as $cast_member ) {
					$taxonomy = '';
					if ( stristr( $cast_member->attributes()->department, 'directing' ) ) {
						$directors[] = $cast_member->attributes()->name;
						$taxonomy = 'movie_director';
					}
					if ( stristr( $cast_member->attributes()->department, 'writing' ) ) {
						$writers[] = $cast_member->attributes()->name;
						$taxonomy = 'movie_writer';
					}
					if ( stristr( $cast_member->attributes()->department, 'actors' ) ) {
						$actors[] = $cast_member->attributes()->name;
						$taxonomy = 'movie_actor';
					}

					// store tmdb person ID by their term id
					if ( ! empty( $taxonomy ) ) {
						$person = wp_create_term( $cast_member->attributes()->name, $taxonomy );
						add_option( 'tmdb_person_' . $person, $cast_member->attributes()->id );
					}
				}

				$certificate = isset( $movie_data->movies->movie->certification ) ? $movie_data->movies->movie->certification : '';
				$certificate = ! empty( $certificate ) ? $certificate : '';

				// set terms
				wp_set_post_terms( $post_id, implode( ', ', $actors ), 'movie_actor', false );
				wp_set_post_terms( $post_id, implode( ', ', $genres ), 'movie_genre', false );
				wp_set_post_terms( $post_id, implode( ', ', $directors ), 'movie_director', false );
				wp_set_post_terms( $post_id, implode( ', ', $writers ), 'movie_writer', false );
				wp_set_post_terms( $post_id, $certificate, 'movie_certificate', false );

				// set post content to overview
				$post->post_content = wpautop( wptexturize( $movie_data->movies->movie->overview ) );
				wp_update_post( $post );

			} catch( Exception $error ) {

				// this will take us back to the movies list at least
				delete_post_meta( $post_id, 'tmdb_movie_id' );
				delete_post_meta( $post_id, 'tmdb_movie_data' );

			}

			do_action( 'save_movie_on_select', $post_id, $post );

		}

		// standard post update
		if ( isset( $_POST[ 'tmdb_movie_trailer' ] ) )
			update_post_meta( $post_id, 'tmdb_movie_trailer', esc_url( $_POST[ 'tmdb_movie_trailer' ] ) );

		do_action( 'save_movie', $post_id, $post );

	}
}

new tmdb_save_post();


/**
 * Get data from the movie database XML response
 *
 * @param String $key     The object property to return a value for eg. trailer, budget, url etc...
 * @param Integer $post_id A valid post id to get the data from
 *
 * @return Mixed    returns the string value or object
 */
function tmdb_movie_info( $key = '', $post_id = 0 ) {
	global $tmdb_api;
	return $tmdb_api->get_info( $key, $post_id );
}


// post class
add_filter( 'post_class', 'tmdb_post_class', 10, 3 );
function tmdb_post_class( $classes, $class, $post_id ) {
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

?>
