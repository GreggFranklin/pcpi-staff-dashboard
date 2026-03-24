<?php
/**
 * PCPI Staff Dashboard - Secure Links
 *
 * Builds signed, expiring links to the admin-ajax endpoint.
 * TTL is configurable via config key: PDF_LINK_TTL_DAYS (default 7).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Small helper: safe legacy config fetch.
 */
function pcpi_staff_dashboard_get_cfg(): array {
	return function_exists( 'pcpi_polygraph_gf_config' )
		? (array) pcpi_polygraph_gf_config()
		: [];
}

/**
 * Salt context for wp_salt().
 * You can filter this if needed, but keep it stable across environments.
 */
function pcpi_staff_dashboard_salt_context(): string {
	$ctx = (string) apply_filters( 'pcpi_staff_dashboard_salt_context', 'pcpi_polygraph_links' );
	return $ctx !== '' ? $ctx : 'pcpi_polygraph_links';
}

/**
 * Debug toggle for endpoint/link debugging.
 */
function pcpi_pdf_link_debug_enabled(): bool {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		return true;
	}
	return (bool) apply_filters( 'pcpi_pdf_link_debug', false );
}

/**
 * Build a secure, expiring PDF link (admin-ajax endpoint).
 *
 * IMPORTANT:
 * - We DO NOT include the PDF ID in the URL (do not trust URL pid).
 * - Endpoint resolves the correct PDF ID via WFE (preferred) or legacy config.
 */
function pcpi_build_secure_pdf_link( int $review_entry_id ): string {

	$rid = absint( $review_entry_id );
	if ( $rid <= 0 ) {
		return '';
	}

	$cfg      = pcpi_staff_dashboard_get_cfg();
	$ttl_days = isset( $cfg['PDF_LINK_TTL_DAYS'] ) ? absint( $cfg['PDF_LINK_TTL_DAYS'] ) : 7;
	if ( $ttl_days <= 0 ) {
		$ttl_days = 7;
	}

	$args = [
		'action'     => 'pcpi_pdf_dl',
		'rid'        => $rid,
		'pcpi_exp'   => time() + ( $ttl_days * DAY_IN_SECONDS ),
		'pcpi_nonce' => wp_generate_password( 24, false, false ),
	];

	ksort( $args );

	$ajax_url = admin_url( 'admin-ajax.php' );
	$path     = (string) wp_parse_url( $ajax_url, PHP_URL_PATH ) ?: '/wp-admin/admin-ajax.php';

	$sig_base = $path . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );

	$args['pcpi_sig'] = hash_hmac(
		'sha256',
		$sig_base,
		wp_salt( pcpi_staff_dashboard_salt_context() )
	);

	return add_query_arg( $args, $ajax_url );
}

/**
 * Friendly error page.
 */
function pcpi_staff_dashboard_pdf_die( int $status, string $reason, array $dbg = [] ): void {

	status_header( $status );

	$out  = '<div style="max-width:900px;margin:48px auto;padding:0 16px;font-family:system-ui">';
	$out .= '<div style="border:1px solid #f3b7b7;background:#fff5f5;border-radius:12px;padding:18px">';
	$out .= '<h2 style="margin:0 0 8px;color:#b00020">PDF not available</h2>';
	$out .= '<p>This link is invalid, expired, or the PDF could not be generated.</p>';

	if ( pcpi_pdf_link_debug_enabled() && $dbg ) {
		$out .= '<pre style="margin-top:12px;background:#fff;border:1px solid #eee;padding:12px">';
		$out .= esc_html( wp_json_encode(
			[ 'reason' => $reason, 'debug' => $dbg ],
			JSON_PRETTY_PRINT
		) );
		$out .= '</pre>';
	}

	$out .= '</div></div>';

	wp_die( $out, 'PDF not available', [ 'response' => $status ] );
}
