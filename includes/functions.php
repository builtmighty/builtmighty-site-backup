<?php
/**
 * Shared utility functions for the BM Site Backup plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check whether the current user belongs to an allowed email domain.
 *
 * Used by settings, dev mode, and devcontainer classes to restrict
 * plugin access to authorized team members.
 *
 * @return bool
 */
function bm_backup_is_authorized_user(): bool {
	$user = wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return false;
	}

	$allowed_domains = apply_filters( 'bm_backup_admin_domains', [ 'builtmighty.com' ] );
	$email           = strtolower( $user->user_email );

	foreach ( $allowed_domains as $domain ) {
		if ( str_ends_with( $email, '@' . strtolower( $domain ) ) ) {
			return true;
		}
	}

	return false;
}
