<?php
/**
 * Plugin Name: _PCPI Staff dashboard
 * Description: Staff dashboard tools for the PCPI Gravity Forms workflow: [gf_entries_table] applicant list with actions (Review/Summary/Send PDF/Resend/Delete), workflow-aware signed questionnaire links, and signed expiring PDF links (per-workflow PDF ID) via admin-ajax.
 * Version:     1.0.19
 * Author:      Gregg Franklin, Marc Benzakein
 * License:     GPLv2 or later
 *
 * Shortcode:
 *   [gf_entries_table form_id="1" last_name_field="1.6" first_name_field="1.3" limit="25" debug="no"]
 *
 * Pagination:
 * - limit = per-page size (backward compatible)
 * - page_var = query arg for the page number (default: pcpi_page)
 *   Example: /staff-dashboard/?pcpi_page=2
 *
 * Secure PDF link:
 * - Signed, expiring link which streams the Gravity PDF output for the Review entry ID (Form 23 entry id).
 * - Link format:
 *   /?pcpi_pdf_dl=1&rid=123&pcpi_exp=...&pcpi_nonce=...&pcpi_sig=...
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -----------------------------------------------------------------------------
// Constants
// -----------------------------------------------------------------------------
// New names (preferred)
if ( ! defined( 'PCPI_SD_VERSION' ) ) { define( 'PCPI_SD_VERSION', '1.0.16' ); }
if ( ! defined( 'PCPI_SD_FILE' ) )    { define( 'PCPI_SD_FILE', __FILE__ ); }
if ( ! defined( 'PCPI_SD_DIR' ) )     { define( 'PCPI_SD_DIR', plugin_dir_path( __FILE__ ) ); }
if ( ! defined( 'PCPI_SD_URL' ) )     { define( 'PCPI_SD_URL', plugin_dir_url( __FILE__ ) ); }

// Back-compat aliases (older builds referenced these)
if ( ! defined( 'PCPI_PGFT_VERSION' ) ) { define( 'PCPI_PGFT_VERSION', PCPI_SD_VERSION ); }
if ( ! defined( 'PCPI_PGFT_FILE' ) )    { define( 'PCPI_PGFT_FILE', PCPI_SD_FILE ); }
if ( ! defined( 'PCPI_PGFT_DIR' ) )     { define( 'PCPI_PGFT_DIR', PCPI_SD_DIR ); }
if ( ! defined( 'PCPI_PGFT_URL' ) )     { define( 'PCPI_PGFT_URL', PCPI_SD_URL ); }
/**
 * Staff Dashboard config (shared by shortcode + AJAX handlers).
 *
 * Goals:
 * - Use the Workflow Engine registry as the single source of truth for workflow-specific IDs.
 * - Keep safe defaults so the dashboard doesn't fatal if the engine is inactive.
 *
 * @return array<string,mixed>
 */
if ( ! function_exists( 'pcpi_staff_dashboard_config' ) ) {
	function pcpi_staff_dashboard_config() : array {
		// Minimal, safe defaults (used only if the Workflow Engine is not available).
		$cfg = [
			// Forms (fallbacks)
			'PARENT_FORM_ID'        => 4, // Applicant form
			'QUESTIONNAIRE_FORM_ID' => 1, // Questionnaire (source)
			'REVIEW_FORM_ID'        => 3, // Review
			'REVIEW_FORM_IDS'       => [ 3 ],

			// Relationship field IDs (optional; Workflow Engine should supply these per workflow)
			'Q_PARENT_APPLICANT_EID_FID' => '',
			'Q_REVIEW_DONE_FLAG_FID'     => '',
			'REVIEW_PARENT_Q_EID_FID'    => '',
			'REVIEW_PARENT_A_EID_FID'    => '',

			// Pages (fallbacks)
			'QUESTIONNAIRE_PAGE_PATH' => '/questionnaire/',
			'REVIEW_PAGE_PATH'        => '/review/',

			// Applicant workflow dropdown field id (Applicant form)
			'APPLICANT_WORKFLOW_FID' => '1005',
			'DEFAULT_WORKFLOW_KEY'   => 'polygraph',

			// Gravity PDF
			'PDF_ID'           => '69792b15ef8e7',
			'PDF_LINK_TTL_DAYS' => 7,

			// Resend
			'RESEND_NOTIFICATION_NAME' => 'Questionnaire link',

			// Agency fields (Applicant form)
			'AGENCY_NAME_FID'  => '6',
			'AGENCY_EMAIL_FID' => '7',
		];

		// ------------------------------------------------------------------
		// Prefer Workflow Engine registry (single source of truth)
		// ------------------------------------------------------------------
		if ( class_exists( 'PCPI_Workflow_Engine' ) && method_exists( 'PCPI_Workflow_Engine', 'get_workflows' ) ) {
			$workflows = (array) PCPI_Workflow_Engine::get_workflows();

			// Default workflow key is only for legacy fallbacks; the dashboard itself
			// resolves the workflow key per applicant entry via APPLICANT_WORKFLOW_FID.
			$default_key = (string) ( $cfg['DEFAULT_WORKFLOW_KEY'] ?? 'polygraph' );
			if ( ! $default_key && ! empty( $workflows ) ) {
				$keys = array_keys( $workflows );
				$default_key = isset( $keys[0] ) ? (string) $keys[0] : '';
			}

			if ( $default_key && isset( $workflows[ $default_key ] ) && is_array( $workflows[ $default_key ] ) ) {
				$wf = (array) $workflows[ $default_key ];

				// Forms / workflow field.
				if ( ! empty( $wf['applicant_form_id'] ) ) {
					$cfg['PARENT_FORM_ID'] = (int) $wf['applicant_form_id'];
				}
				if ( ! empty( $wf['applicant_workflow_field_id'] ) ) {
					$cfg['APPLICANT_WORKFLOW_FID'] = (string) (int) $wf['applicant_workflow_field_id'];
				}
				if ( ! empty( $wf['source_form_id'] ) ) {
					$cfg['QUESTIONNAIRE_FORM_ID'] = (int) $wf['source_form_id'];
				}
				if ( ! empty( $wf['review_form_id'] ) ) {
					$cfg['REVIEW_FORM_ID']  = (int) $wf['review_form_id'];
					$cfg['REVIEW_FORM_IDS'] = [ (int) $wf['review_form_id'] ];
				}

				// Relationship anchors.
				if ( ! empty( $wf['questionnaire_parent_applicant_field_id'] ) ) {
					$cfg['Q_PARENT_APPLICANT_EID_FID'] = (string) (int) $wf['questionnaire_parent_applicant_field_id'];
				}
				if ( ! empty( $wf['review_parent_questionnaire_field_id'] ) ) {
					$cfg['REVIEW_PARENT_Q_EID_FID'] = (string) (int) $wf['review_parent_questionnaire_field_id'];
				}
				if ( ! empty( $wf['review_parent_applicant_field_id'] ) ) {
					$cfg['REVIEW_PARENT_A_EID_FID'] = (string) (int) $wf['review_parent_applicant_field_id'];
				}

				// Pages.
				if ( ! empty( $wf['questionnaire_page_path'] ) ) {
					$cfg['QUESTIONNAIRE_PAGE_PATH'] = (string) $wf['questionnaire_page_path'];
				}
				if ( ! empty( $wf['review_page_path'] ) ) {
					$cfg['REVIEW_PAGE_PATH'] = (string) $wf['review_page_path'];
				}

				// PDF.
				if ( ! empty( $wf['pdf_id'] ) ) {
					$cfg['PDF_ID'] = (string) $wf['pdf_id'];
				}
				if ( isset( $wf['pdf_link_ttl_days'] ) && (int) $wf['pdf_link_ttl_days'] > 0 ) {
					$cfg['PDF_LINK_TTL_DAYS'] = (int) $wf['pdf_link_ttl_days'];
				}

				$cfg['DEFAULT_WORKFLOW_KEY'] = $default_key;
			}
		}

		/**
		 * Final hook so the site can override without editing plugin files.
		 *
		 * @param array<string,mixed> $cfg
		 */
		return (array) apply_filters( 'pcpi_staff_dashboard_config', $cfg );
	}
}

/**
 * Back-compat: older builds call pcpi_polygraph_gf_config().
 * Keep it as a thin alias so we don't break any includes.
 */
if ( ! function_exists( 'pcpi_polygraph_gf_config' ) ) {
	function pcpi_polygraph_gf_config() : array {
		return pcpi_staff_dashboard_config();
	}
}

require_once PCPI_SD_DIR . 'includes/class-pcpi-staff-dashboard.php';
require_once PCPI_SD_DIR . 'includes/security-links.php';

PCPI_Staff_Dashboard::init();
