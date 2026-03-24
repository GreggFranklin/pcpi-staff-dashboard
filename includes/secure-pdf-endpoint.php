<?php
/**
 * PCPI Staff Dashboard - Secure PDF Endpoint
 *
 * Receives signed, expiring links and redirects to Gravity PDF native endpoint:
 *   /pdf/{pdf_id}/{entry_id}/
 *
 * Bulletproof behavior:
 * - Validates signature + expiry
 * - Resolves rid:
 *     - If rid is a Review entry, resolves parent Questionnaire entry id
 *     - If rid is already Questionnaire, uses it directly
 * - Resolves PDF ID:
 *     - Preferred: PCPI Workflow Engine registry (WFE)
 *     - Fallback:  legacy pcpi_polygraph_gf_config()
 * - Does NOT trust pcpi_pid from URL (but will accept old links that included it)
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/secure-pdf-link.php';

/**
 * Small logger (one line per request when debug enabled).
 */
function pcpi_staff_dashboard_pdf_log( string $msg, array $ctx = [] ): void {
	if ( ! pcpi_pdf_link_debug_enabled() ) {
		return;
	}
	$line = '[PCPI PDF] ' . $msg;
	if ( $ctx ) {
		$line .= ' ' . wp_json_encode( $ctx );
	}
	error_log( $line );
}

/**
 * Attempt to load workflows from Workflow Engine.
 *
 * @return array<string,array<string,mixed>>
 */
function pcpi_wfe_get_workflows_safe(): array {
	if ( class_exists( 'PCPI_Workflow_Engine' ) && method_exists( 'PCPI_Workflow_Engine', 'get_workflows' ) ) {
		$wf = (array) PCPI_Workflow_Engine::get_workflows();
		return $wf;
	}
	return [];
}

/**
 * Find a workflow config based on a form_id.
 *
 * Matches:
 * - review_form_id OR review_form_ids contains form_id
 * - source_form_id
 *
 * @return array{key:string, cfg:array<string,mixed>}|null
 */
function pcpi_wfe_match_workflow_for_form( int $form_id ): ?array {

	$workflows = pcpi_wfe_get_workflows_safe();
	if ( ! $workflows ) {
		return null;
	}

	foreach ( $workflows as $key => $wf ) {
		$wf = (array) $wf;

		$source_form_id = isset( $wf['source_form_id'] ) ? absint( $wf['source_form_id'] ) : 0;

		$review_form_id  = isset( $wf['review_form_id'] ) ? absint( $wf['review_form_id'] ) : 0;
		$review_form_ids = [];
		if ( isset( $wf['review_form_ids'] ) && is_array( $wf['review_form_ids'] ) ) {
			$review_form_ids = array_map( 'absint', $wf['review_form_ids'] );
		}

		if ( $form_id === $source_form_id ) {
			return [ 'key' => (string) $key, 'cfg' => $wf ];
		}

		if ( $review_form_id && $form_id === $review_form_id ) {
			return [ 'key' => (string) $key, 'cfg' => $wf ];
		}

		if ( $review_form_ids && in_array( $form_id, $review_form_ids, true ) ) {
			return [ 'key' => (string) $key, 'cfg' => $wf ];
		}
	}

	return null;
}

/**
 * Resolve (workflow, questionnaire entry id, pdf id) from an arbitrary entry.
 *
 * @return array{
 *   workflow_key:string,
 *   workflow:array<string,mixed>,
 *   source_form_id:int,
 *   source_entry_id:int,
 *   pdf_id:string
 * }
 */
function pcpi_resolve_pdf_context_from_rid( int $rid ): array {

	// Guardrails
	if ( ! class_exists( 'GFAPI' ) ) {
		pcpi_staff_dashboard_pdf_die( 500, 'gfapi_missing' );
	}

	$entry = GFAPI::get_entry( $rid );
	if ( is_wp_error( $entry ) || empty( $entry ) ) {
		pcpi_staff_dashboard_pdf_die( 404, 'entry_not_found', [ 'rid' => $rid ] );
	}

	$form_id = isset( $entry['form_id'] ) ? absint( $entry['form_id'] ) : 0;

	// 1) Preferred: WFE lookup by form_id
	$matched = pcpi_wfe_match_workflow_for_form( $form_id );

	// 2) Fallback: legacy config only (single workflow)
	$legacy_cfg = pcpi_staff_dashboard_get_cfg();

	$workflow_key = $matched ? $matched['key'] : (string) ( $legacy_cfg['DEFAULT_WORKFLOW_KEY'] ?? 'legacy' );
	$workflow     = $matched ? (array) $matched['cfg'] : [];

	// Determine source form id
	$source_form_id = isset( $workflow['source_form_id'] )
		? absint( $workflow['source_form_id'] )
		: absint( $legacy_cfg['QUESTIONNAIRE_FORM_ID'] ?? 0 );

	// Determine review form ids (for resolution logic)
	$review_form_id = isset( $workflow['review_form_id'] )
		? absint( $workflow['review_form_id'] )
		: absint( $legacy_cfg['REVIEW_FORM_ID'] ?? 0 );

	$review_form_ids = [];
	if ( isset( $workflow['review_form_ids'] ) && is_array( $workflow['review_form_ids'] ) ) {
		$review_form_ids = array_map( 'absint', $workflow['review_form_ids'] );
	} elseif ( isset( $legacy_cfg['REVIEW_FORM_IDS'] ) && is_array( $legacy_cfg['REVIEW_FORM_IDS'] ) ) {
		$review_form_ids = array_map( 'absint', $legacy_cfg['REVIEW_FORM_IDS'] );
	}

	$is_review = ( $review_form_id && $form_id === $review_form_id )
		|| ( $review_form_ids && in_array( $form_id, $review_form_ids, true ) );

	// Resolve source entry id
	$source_entry_id = 0;

	if ( $source_form_id && $form_id === $source_form_id ) {
		$source_entry_id = $rid;
	} elseif ( $is_review ) {

		// Prefer WFE field id; fallback to legacy config field id.
		$parent_q_fid = isset( $workflow['review_parent_questionnaire_field_id'] )
			? absint( $workflow['review_parent_questionnaire_field_id'] )
			: absint( $legacy_cfg['REVIEW_PARENT_Q_EID_FID'] ?? 0 );

		$qid = 0;
		if ( $parent_q_fid > 0 ) {
			// GF stores values by string field id key
			$qid = isset( $entry[ (string) $parent_q_fid ] ) ? absint( $entry[ (string) $parent_q_fid ] ) : 0;
		}

		if ( $qid <= 0 ) {
			pcpi_staff_dashboard_pdf_die( 500, 'missing_parent_qid', [
				'rid'          => $rid,
				'form_id'      => $form_id,
				'parent_q_fid' => $parent_q_fid,
			] );
		}

		$source_entry_id = $qid;
	} else {
		pcpi_staff_dashboard_pdf_die( 400, 'wrong_form', [
			'rid'     => $rid,
			'form_id' => $form_id,
			'source_form_id' => $source_form_id,
			'review_form_id' => $review_form_id,
			'review_form_ids' => $review_form_ids,
		] );
	}

	// Resolve PDF ID (WFE preferred)
	$pdf_id = '';
	if ( isset( $workflow['pdf_id'] ) ) {
		$pdf_id = trim( (string) $workflow['pdf_id'] );
	} elseif ( isset( $workflow['PDF_ID'] ) ) { // tolerate older naming
		$pdf_id = trim( (string) $workflow['PDF_ID'] );
	}

	// Legacy fallback
	if ( $pdf_id === '' ) {
		$pdf_id = trim( (string) ( $legacy_cfg['PDF_ID'] ?? '' ) );
	}

	if ( $pdf_id === '' ) {
		pcpi_staff_dashboard_pdf_die( 500, 'missing_pdf_id_in_config', [
			'rid' => $rid,
			'workflow_key' => $workflow_key,
		] );
	}

	return [
		'workflow_key'   => $workflow_key,
		'workflow'       => $workflow,
		'source_form_id' => $source_form_id,
		'source_entry_id'=> $source_entry_id,
		'pdf_id'         => $pdf_id,
	];
}

function pcpi_staff_dashboard_ajax_pdf_dl(): void {

	$rid   = isset( $_GET['rid'] ) ? absint( $_GET['rid'] ) : 0;
	$exp   = isset( $_GET['pcpi_exp'] ) ? absint( $_GET['pcpi_exp'] ) : 0;
	$nonce = isset( $_GET['pcpi_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['pcpi_nonce'] ) ) : '';
	$sig   = isset( $_GET['pcpi_sig'] ) ? sanitize_text_field( wp_unslash( $_GET['pcpi_sig'] ) ) : '';

	// Legacy param (we do not trust it, but we may accept signatures that included it historically)
	$pid = isset( $_GET['pcpi_pid'] ) ? sanitize_text_field( wp_unslash( $_GET['pcpi_pid'] ) ) : '';

	if ( ! $rid || ! $exp || ! $nonce || ! $sig ) {
		pcpi_staff_dashboard_pdf_die( 400, 'missing_params', compact( 'rid', 'exp' ) );
	}

	if ( time() > $exp ) {
		pcpi_staff_dashboard_pdf_die( 410, 'expired', compact( 'rid', 'exp' ) );
	}

	// Signature validation: accept either signing style:
	// - current: action,rid,exp,nonce
	// - legacy:  action,rid,exp,nonce,pcpi_pid (if present in URL)
	$ajax_url = admin_url( 'admin-ajax.php' );
	$path     = (string) wp_parse_url( $ajax_url, PHP_URL_PATH ) ?: '/wp-admin/admin-ajax.php';

	$base_args = [
		'action'     => 'pcpi_pdf_dl',
		'rid'        => $rid,
		'pcpi_exp'   => $exp,
		'pcpi_nonce' => $nonce,
	];
	ksort( $base_args );

	$expected_a = hash_hmac(
		'sha256',
		$path . '?' . http_build_query( $base_args, '', '&', PHP_QUERY_RFC3986 ),
		wp_salt( pcpi_staff_dashboard_salt_context() )
	);

	$ok = hash_equals( $expected_a, $sig );

	// If not ok and pid present, try legacy signed-with-pid variant
	if ( ! $ok && $pid !== '' ) {
		$pid_args = $base_args;
		$pid_args['pcpi_pid'] = $pid;
		ksort( $pid_args );

		$expected_b = hash_hmac(
			'sha256',
			$path . '?' . http_build_query( $pid_args, '', '&', PHP_QUERY_RFC3986 ),
			wp_salt( pcpi_staff_dashboard_salt_context() )
		);

		$ok = hash_equals( $expected_b, $sig );
	}

	if ( ! $ok ) {
		pcpi_staff_dashboard_pdf_die( 403, 'bad_sig', [ 'rid' => $rid ] );
	}

	// Guardrails
	if ( ! class_exists( 'GFAPI' ) ) {
		pcpi_staff_dashboard_pdf_die( 500, 'gfapi_missing' );
	}

	// Resolve context (WFE first, legacy fallback)
	$ctx = pcpi_resolve_pdf_context_from_rid( $rid );

	// If pid was provided, ignore it (but log mismatch if debugging)
	if ( $pid !== '' && $pid !== $ctx['pdf_id'] ) {
		pcpi_staff_dashboard_pdf_log( 'pid_mismatch_ignored', [
			'rid' => $rid,
			'pid' => $pid,
			'resolved_pdf_id' => $ctx['pdf_id'],
			'workflow_key' => $ctx['workflow_key'],
		] );
	}

	// Final redirect target uses resolved questionnaire entry id, not review rid.
	$pdf_url = site_url( sprintf(
		'/pdf/%s/%d/',
		rawurlencode( (string) $ctx['pdf_id'] ),
		(int) $ctx['source_entry_id']
	) );

	pcpi_staff_dashboard_pdf_log( 'redirect', [
		'rid'            => $rid,
		'resolved_entry' => (int) $ctx['source_entry_id'],
		'pdf_id'         => (string) $ctx['pdf_id'],
		'workflow_key'   => (string) $ctx['workflow_key'],
		'pdf_url'        => $pdf_url,
	] );

	// Keep caches honest on the signed hop.
	nocache_headers();
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );

	wp_safe_redirect( $pdf_url, 302 );
	exit;
}

add_action( 'wp_ajax_pcpi_pdf_dl', 'pcpi_staff_dashboard_ajax_pdf_dl', 0 );
add_action( 'wp_ajax_nopriv_pcpi_pdf_dl', 'pcpi_staff_dashboard_ajax_pdf_dl', 0 );
