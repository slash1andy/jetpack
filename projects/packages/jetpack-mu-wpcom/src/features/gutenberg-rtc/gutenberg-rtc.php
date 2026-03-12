<?php
/**
 * Gutenberg RTC (Real-Time Collaboration) customizations
 * This handles RTC-related configurations for the Gutenberg editor on JP sites.
 *
 * Currently disables HTTP polling to prevent issues, but can be extended
 * in the future for other RTC-related customizations.
 *
 * @package automattic/jetpack-mu-wpcom
 */

/**
 * Determines whether Gutenberg RTC is enabled.
 *
 * Disabled by default until the PingHub provider is ready
 * and we are confident in proceeding with the rollout.
 */
function wpcom_is_gutenberg_rtc_enabled() {
	$is_enabled = false;
	if ( function_exists( 'wpcom_site_has_feature' ) && class_exists( 'WPCOM_Features' ) ) {
		$blog_id    = get_wpcom_blog_id();
		$is_enabled = wpcom_site_has_feature( WPCOM_Features::REAL_TIME_COLLABORATION, $blog_id );
	}

	return apply_filters( 'wpcom_is_gutenberg_rtc_enabled', $is_enabled );
}

/**
 * Determine if HTTP polling should be enforced for the current blog.
 *
 * @return bool True if HTTP polling should be enforced for the current blog, false otherwise.
 */
function should_enforce_http_polling_for_blog() {
	$blog_id = get_wpcom_blog_id();

	if ( defined( 'IS_ATOMIC' ) && IS_ATOMIC && ( $blog_id % 100 === 1 ) ) {
		return true;
	}
	return false;
}

/**
 * Get WPCOM RTC providers.
 */
function wpcom_get_gutenberg_rtc_providers() {
	if ( should_enforce_http_polling_for_blog() ) {
		return array( 'http-polling' );
	}

	if ( ! wpcom_is_gutenberg_rtc_enabled() ) {
		return array();
	}

	$allowed_providers = array( 'http-polling', 'pinghub' );
	$providers         = apply_filters( 'wpcom_gutenberg_rtc_providers', array( 'pinghub' ) );
	if ( ! is_array( $providers ) ) {
		return array();
	}

	return array_values(
		array_filter(
			$providers,
			function ( $provider ) use ( $allowed_providers ) {
				return in_array( $provider, $allowed_providers, true );
			}
		)
	);
}

/**
 * Enqueue block editor assets for Gutenberg RTC customizations.
 */
function wpcom_enqueue_gutenberg_rtc_assets() {
	$handle = jetpack_mu_wpcom_enqueue_assets( 'gutenberg-rtc', array( 'js' ) );

	$data = wp_json_encode(
		array(
			'providers'     => wpcom_get_gutenberg_rtc_providers(),
			'roomUserLimit' => wpcom_rtc_get_max_collaborators(),
		),
		JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
	);

	wp_add_inline_script(
		$handle,
		"var wpcomGutenbergRTC = $data;",
		'before'
	);
}
add_action( 'enqueue_block_editor_assets', 'wpcom_enqueue_gutenberg_rtc_assets' );

/**
 * Unregister the RTC setting field, Collaboration, on the Writing page if there are no RTC providers.
 */
function wpcom_unregister_rtc_setting() {
	global $wp_settings_fields;

	$providers   = wpcom_get_gutenberg_rtc_providers();
	$option_name = 'wp_enable_real_time_collaboration';
	if ( isset( $wp_settings_fields['writing']['default'][ $option_name ] ) && count( $providers ) === 0 ) {
		unset( $wp_settings_fields['writing']['default'][ $option_name ] );
	}

	// TODO: Clean up the old name. See https://github.com/WordPress/gutenberg/pull/75837.
	$option_name = 'enable_real_time_collaboration';
	if ( isset( $wp_settings_fields['writing']['default'][ $option_name ] ) && count( $providers ) === 0 ) {
		unset( $wp_settings_fields['writing']['default'][ $option_name ] );
	}
}
add_action( 'admin_init', 'wpcom_unregister_rtc_setting', 11 );

/**
 * Disable the `wp_enable_real_time_collaboration` option if there are no RTC providers.
 *
 * @param mixed $pre_option The value to return instead of the option value.
 * @return string|false Filtered wp_enable_real_time_collaboration option
 */
function wpcom_disable_rtc_option( $pre_option ) {
	$providers = wpcom_get_gutenberg_rtc_providers();
	if ( count( $providers ) === 0 ) {
		return '0';
	}

	return $pre_option;
}
add_filter( 'pre_option_wp_enable_real_time_collaboration', 'wpcom_disable_rtc_option' );
add_filter( 'pre_option_enable_real_time_collaboration', 'wpcom_disable_rtc_option' ); // TODO: Clean up the old name. See https://github.com/WordPress/gutenberg/pull/75837.

/**
 * Get the maximum number of simultaneous RTC collaborators allowed per room.
 *
 * @return int Maximum collaborator count. 0 means unlimited.
 */
function wpcom_rtc_get_max_collaborators() {
	return (int) apply_filters( 'wpcom_rtc_max_collaborators', 2 );
}

/**
 * Count active collaborators in a room, excluding the current collaborator.
 *
 * Uses awareness `state.collaboratorInfo.id` (matching frontend semantics).
 *
 * @param array<int, array<string, mixed>> $awareness_state Awareness entries for the room.
 * @param int                              $current_user_id Current WordPress user ID.
 * @param int                              $now Current Unix timestamp.
 * @return int Number of active collaborators other than the requester.
 */
function wpcom_rtc_count_active_other_collaborators( array $awareness_state, $current_user_id, $now ) {
	$seen_user_ids = array();

	foreach ( $awareness_state as $entry ) {
		$entry_updated_at = isset( $entry['updated_at'] ) ? (int) $entry['updated_at'] : 0;
		// Only count clients within the awareness timeout (30s).
		if ( ( $now - $entry_updated_at ) >= 30 ) {
			continue;
		}
		$entry_user_id = isset( $entry['state']['collaboratorInfo']['id'] ) ? (int) $entry['state']['collaboratorInfo']['id'] : 0;
		if ( $entry_user_id > 0 ) {
			if ( $entry_user_id === $current_user_id || isset( $seen_user_ids[ $entry_user_id ] ) ) {
				continue;
			}
			$seen_user_ids[ $entry_user_id ] = true;
		}
	}

	return count( $seen_user_ids );
}

/**
 * Limit the number of simultaneous RTC collaborators per room.
 *
 * Hooks into `rest_pre_dispatch` to check the current awareness state before
 * allowing a new collaborator to join a sync room. If the number of active
 * collaborators (excluding the requesting user) meets or exceeds the limit,
 * a 429 error is returned.
 *
 * @param mixed           $result  Response to replace the requested version with. Can be anything
 *                                 a normal endpoint can return, or null to not hijack the request.
 * @param WP_REST_Server  $server  Server instance.
 * @param WP_REST_Request $request Request used to generate the response.
 * @return mixed|WP_Error Original result or WP_Error if limit exceeded.
 */
function wpcom_rtc_limit_collaborators( $result, $server, $request ) {
	if ( null !== $result ) {
		return $result;
	}

	$route = $request->get_route();
	if ( '/wp-sync/v1/updates' !== $route ) {
		return $result;
	}

	// Only enforce for HTTP polling ramp-up sites; PingHub handles its own limits.
	if ( ! should_enforce_http_polling_for_blog() ) {
		return $result;
	}

	$max_collaborators = wpcom_rtc_get_max_collaborators();
	if ( $max_collaborators <= 0 ) {
		return $result;
	}

	if ( ! class_exists( 'WP_Sync_Post_Meta_Storage' ) ) {
		return $result;
	}

	$rooms = $request['rooms'];
	if ( ! is_array( $rooms ) ) {
		return $result;
	}

	$storage         = new WP_Sync_Post_Meta_Storage(); // @phan-suppress-current-line PhanUndeclaredClassMethod -- Guarded by class_exists() above.
	$now             = time();
	$current_user_id = get_current_user_id();

	foreach ( $rooms as $room_request ) {
		$room = $room_request['room'] ?? '';

		$existing      = $storage->get_awareness_state( $room ); // @phan-suppress-current-line PhanUndeclaredClassMethod
		$active_others = wpcom_rtc_count_active_other_collaborators( $existing, $current_user_id, $now );

		if ( $active_others >= $max_collaborators ) {
			return new WP_Error(
				'rest_sync_connection_limit_exceeded',
				__( 'Too many editors connected.', 'jetpack-mu-wpcom' ),
				array( 'status' => 429 )
			);
		}
	}

	return $result;
}
add_filter( 'rest_pre_dispatch', 'wpcom_rtc_limit_collaborators', 10, 3 );
