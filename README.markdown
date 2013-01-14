# The Movie Database API for WordPress

This plugin is still in development but works well enough to use. I have recetnly updated it to use the TMDb API class with support for API v3 as v2.1 is being deprecated.

It provides a general wrapper for developers to use the API while taking advantage of WordPress's built in caching methods, post types and custom taxonomies.

By default the plugin creates a post type called 'movies' but this can be deregistered. To use the movie database functionality with another or existing post type simply do the following in your theme's functions.php:

    add_post_type_support( 'post_type', 'movie' );

This will register all the necessary meta boxes and taxonomies for that post type. You will get a metabox on the post type edit screen with a search box to track down the movie you're after. After selecting that you can choose from the movies the plugin has found and then the plugin will populate the taxonomies for Actor, Director, Genre, Certificate and so on. You will also get a button to asynchronously grab any images associated with the movie including backdrops and posters and the movie trailer field will be populated for you. You can always change the trailer URL if you find a better one.

## Using the API

You can use the API in 2 ways, either directly by creating a new instance of the TMDb class or through the plugin caching layer.

To use the plugin method do the following:

```php

$tmdb = tmdb_api::instance();

/**
 * WP caching layer on top of TMDb
 *
 * @param string 	$method 	The TMDb method to call
 * @param mixed 	$args   	The args to pass to the method if any
 * @param int 		$expiration How long the api call should be cached for
 *
 * @return mixed    Array response or exception
 */

$method     = 'getMovie'; // a method of the TMDb API class
$args       = 550; // a movie ID
$expiration = 3600; // number of seconds to cache result for

$movies = $tmdb->api_cache( $method, $args, $expiration );

```

The result is an object as defined on themoviedb.org API wiki: http://docs.themoviedb.apiary.io/
