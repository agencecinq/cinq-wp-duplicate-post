<?php
/**
 * Plugin Name: CINQ Duplicate Post
 * Plugin URI: https://github.com/agencecinq/cinq-wp-duplicate-post
 * Description: Adds a Duplicate link to the admin list screens that copies a post into a new draft. No settings screen.
 * Version: 1.0.0
 * Author: CINQ
 * Author URI: https://agencecinq.com/
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: cinq-wp-duplicate-post
 *
 * @package CinqWpDuplicatePost
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post types that can be duplicated.
 *
 * @return array<int, string>
 */
function cinq_wp_duplicate_post_post_types(): array {
	$default_post_types = array();

	foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $post_type ) {
		if ( $post_type->show_in_menu && 'attachment' !== $post_type->name ) {
			$default_post_types[] = $post_type->name;
		}
	}

	/**
	 * Filters the post types that can be duplicated.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $post_types Post type names. Default every post type with its own admin menu, except attachment.
	 */
	$post_types = apply_filters( 'cinq_wp_duplicate_post_post_types', $default_post_types );

	if ( ! is_array( $post_types ) ) {
		return array();
	}

	return array_values( array_filter( $post_types, 'is_string' ) );
}

/**
 * Whether the current user can duplicate a post.
 *
 * @param \WP_Post $post Post to duplicate.
 * @return bool
 */
function cinq_wp_duplicate_post_can_duplicate( \WP_Post $post ): bool {
	if ( 'trash' === $post->post_status ) {
		return false;
	}

	if ( ! in_array( $post->post_type, cinq_wp_duplicate_post_post_types(), true ) ) {
		return false;
	}

	$post_type = get_post_type_object( $post->post_type );

	return current_user_can( 'edit_post', $post->ID ) && current_user_can( $post_type->cap->create_posts );
}

/**
 * Copy a post into a new draft owned by the current user.
 *
 * Copies the content, parent, order, terms and meta. The draft gets its own
 * slug and date when it is published.
 *
 * @param \WP_Post $post Post to duplicate.
 * @return int|\WP_Error Draft ID, or the error from wp_insert_post().
 */
function cinq_wp_duplicate_post( \WP_Post $post ): int|\WP_Error {
	$terms = array();

	foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
		$terms[ $taxonomy ] = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
	}

	/*
	 * Meta that belongs to the original: its edit lock, its last editor, and
	 * its old slugs and dates, which redirect to it.
	 */
	$excluded_meta_keys = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date' );
	$meta               = array();

	foreach ( get_post_meta( $post->ID ) as $key => $values ) {
		if ( ! in_array( $key, $excluded_meta_keys, true ) ) {
			$meta[ $key ] = maybe_unserialize( $values[0] );
		}
	}

	$draft = array(
		'post_type'      => $post->post_type,
		'post_status'    => 'draft',
		'post_author'    => get_current_user_id(),
		'post_title'     => $post->post_title,
		'post_content'   => $post->post_content,
		'post_excerpt'   => $post->post_excerpt,
		'post_password'  => $post->post_password,
		'post_parent'    => $post->post_parent,
		'menu_order'     => $post->menu_order,
		'comment_status' => $post->comment_status,
		'ping_status'    => $post->ping_status,
		'tax_input'      => $terms,
		'meta_input'     => $meta,
	);

	return wp_insert_post( wp_slash( $draft ), true );
}

add_filter( 'post_row_actions', 'cinq_wp_duplicate_post_row_action', 10, 2 );
add_filter( 'page_row_actions', 'cinq_wp_duplicate_post_row_action', 10, 2 );

/**
 * Add the Duplicate link to the row actions of the list screens.
 *
 * @param array<string, string> $actions Row action links.
 * @param \WP_Post              $post    Post of the row.
 * @return array<string, string>
 */
function cinq_wp_duplicate_post_row_action( array $actions, \WP_Post $post ): array {
	if ( ! cinq_wp_duplicate_post_can_duplicate( $post ) ) {
		return $actions;
	}

	$query_args = array(
		'action' => 'cinq_wp_duplicate_post',
		'post'   => $post->ID,
	);

	$url = wp_nonce_url( add_query_arg( $query_args, admin_url( 'admin-post.php' ) ), 'cinq_wp_duplicate_post' );

	/* translators: %s: Post title. */
	$label = sprintf( __( 'Duplicate &#8220;%s&#8221;', 'cinq-wp-duplicate-post' ), _draft_or_post_title( $post ) );

	$actions['duplicate'] = sprintf(
		'<a href="%1$s" aria-label="%2$s">%3$s</a>',
		esc_url( $url ),
		esc_attr( $label ),
		esc_html__( 'Duplicate', 'cinq-wp-duplicate-post' )
	);

	return $actions;
}

add_action( 'admin_post_cinq_wp_duplicate_post', 'cinq_wp_duplicate_post_handle' );

/**
 * Duplicate the requested post, then go back to the list, where the draft shows up.
 *
 * @return void
 */
function cinq_wp_duplicate_post_handle(): void {
	check_admin_referer( 'cinq_wp_duplicate_post' );

	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	$post    = get_post( $post_id );

	if ( ! $post instanceof \WP_Post || ! cinq_wp_duplicate_post_can_duplicate( $post ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to duplicate this item.', 'cinq-wp-duplicate-post' ), 403 );
	}

	$draft_id = cinq_wp_duplicate_post( $post );

	if ( is_wp_error( $draft_id ) ) {
		wp_die( esc_html( $draft_id->get_error_message() ) );
	}

	$referer  = wp_get_referer();
	$list_url = $referer ? $referer : add_query_arg( 'post_type', $post->post_type, admin_url( 'edit.php' ) );

	wp_safe_redirect( $list_url );
	exit;
}
