<?php
// Test only, register public custom content before the Activity Log hooks are exercised.
add_action( 'init', static function (): void {
	register_post_type( 'alfa_book', [
		'labels' => [
			'name' => 'Pilot books',
			'singular_name' => 'Pilot book',
		],
		'public' => true,
		'show_ui' => true,
		'supports' => [ 'title', 'editor', 'excerpt', 'author', 'revisions', 'thumbnail', 'comments', 'page-attributes' ],
	] );
	register_taxonomy( 'alfa_genre', [ 'alfa_book' ], [
		'labels' => [
			'name' => 'Pilot genres',
			'singular_name' => 'Pilot genre',
		],
		'public' => true,
		'show_ui' => true,
		'hierarchical' => true,
	] );
} );
