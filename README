# The Movie Database API for WordPress

This plugin is still in development but works well enough to use.

It provides a general wrapper for developers to use the API while taking advantage of WordPress's built in caching methods, post types and custom taxonomies.

By default the plugin creates a post type called 'movies' but this can be deregistered. To use the movie database functionality with another or existing post type simply do the following in your theme's functions.php:

    add_post_type_support( 'post_type', 'movie' );

This will register all the necessary meta boxes and taxonomies for that post type. You will get a metabox on the post type edit screen with a search box to track down the movie you're after. After selecting that you can choose from the movies the plugin has found and then the plugin will populate the taxonomies for Actor, Director, Genre, Certificate and so on. You will also get a button to asynchronously grab any images associated with the movie including backdrops and posters and the movie trailer field will be populated for you. You can always change the trailer URL if you find a better one.

To use the general API method in code do the following:

    tmdb_api()->get( $method, $args );

where `$method` is the API method to call and `$args` is an associative array or string depending on what the method is expecting.

_Please note: the plugin defaults to XML responses because the JSON returned from the API is broken at the time of writing_

I'll be maintaining this plugin as and when I get the chance or as people start to use it and find issues as they no doubt will :)

If you want to contribute then by all means fork it and go!
