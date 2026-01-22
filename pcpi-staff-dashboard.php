<?php
/**
 * Plugin Name: _PCPI Staff dashboard
 * Description: Staff dashboard tools for the PCPI polygraph Gravity Forms workflow: provides the [gf_entries_table] shortcode (Applicant list with Review/Summary/Send PDF/Resend/Delete actions), AJAX resend of the Applicant “Questionnaire link” notification, AJAX send PDF link to Agency (signed), and cascade deletion across Applicant (Form 1) → Questionnaire (Form 2) → Examiner Review (Form 23), including Gravity PDF Summary link generation.
 * Version:     1.0.6
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

if ( ! defined( 'PCPI_PGFT_VERSION' ) ) {
	define( 'PCPI_PGFT_VERSION', '1.0.6' );
}

if ( ! defined( 'PCPI_PGFT_FILE' ) ) {
	define( 'PCPI_PGFT_FILE', __FILE__ );
}

if ( ! defined( 'PCPI_PGFT_DIR' ) ) {
	define( 'PCPI_PGFT_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'PCPI_PGFT_URL' ) ) {
	define( 'PCPI_PGFT_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Polygraph GF Config (shared by shortcode + AJAX handlers)
 */
if ( ! function_exists( 'pcpi_polygraph_gf_config' ) ) {
	function pcpi_polygraph_gf_config() : array {
		return [
			// Forms
			'PARENT_FORM_ID'        => 1, // Applicant form
			'QUESTIONNAIRE_FORM_ID' => 2, // Questionnaire form

			// Review form (confirmed)
			'REVIEW_FORM_ID'  => 23,
			'REVIEW_FORM_IDS' => [ 23 ],

			// Relationship Field IDs
			'Q_PARENT_APPLICANT_EID_FID' => '579', // Form 2 hidden: parent_applicant_entry_id
			'Q_REVIEW_DONE_FLAG_FID'     => '559', // Form 2: Review Ready flag == '1'

			// Form 23 hidden relational keys
			'REVIEW_PARENT_Q_EID_FID' => '643', // Form 23 hidden: parent_questionnaire_entry_id (legacy 607)
			'REVIEW_PARENT_A_EID_FID' => '608', // Form 23 hidden: parent_applicant_entry_id

			// Gravity PDF (the PDF config you want to send/stream)
			'PDF_ID' => '690f9d2e167ec',

			// RESEND: Notification Name (Form 1)
			'RESEND_NOTIFICATION_NAME' => 'Questionnaire link',

			// Agency fields (Form 1)
			'AGENCY_NAME_FID'  => '6',
			'AGENCY_EMAIL_FID' => '7',

			// PDF link TTL (days)
			'PDF_LINK_TTL_DAYS' => 7,
		];
	}
}

final class PCPI_Polygraph_GF_Tools {

	private static bool $assets_enqueued = false;

	public static function init() : void {
		add_shortcode( 'gf_entries_table', [ __CLASS__, 'shortcode_gf_entries_table' ] );

		// AJAX: Resend
		if ( ! has_action( 'wp_ajax_gf_resend_entry' ) ) {
			add_action( 'wp_ajax_gf_resend_entry', [ __CLASS__, 'ajax_gf_resend_entry' ] );
		}

		// AJAX: Delete (cascade)
		if ( ! has_action( 'wp_ajax_gf_delete_entry' ) ) {
			add_action( 'wp_ajax_gf_delete_entry', [ __CLASS__, 'ajax_gf_delete_entry' ] );
		}

		// AJAX: Send PDF link to agency
		if ( ! has_action( 'wp_ajax_pcpi_send_pdf_link' ) ) {
			add_action( 'wp_ajax_pcpi_send_pdf_link', [ __CLASS__, 'ajax_pcpi_send_pdf_link' ] );
		}
	}

	private static function gf_is_available() : bool {
		return ( class_exists( 'GFForms' ) && class_exists( 'GFFormsModel' ) && class_exists( 'GFAPI' ) );
	}

	private static function user_can_manage() : bool {
		return ( current_user_can( 'gf_manage_entries' ) || current_user_can( 'manage_options' ) );
	}

	private static function maybe_enqueue_assets() : void {
		if ( self::$assets_enqueued ) {
			return;
		}

		if ( ! self::user_can_manage() ) {
			return;
		}

		self::$assets_enqueued = true;

		wp_enqueue_style(
			'pcpi-gf-entries-table',
			PCPI_PGFT_URL . 'assets/css/gf-entries-table.css',
			[],
			PCPI_PGFT_VERSION
		);

		wp_enqueue_script( 'jquery' );

		wp_enqueue_script(
			'pcpi-gf-entries-table',
			PCPI_PGFT_URL . 'assets/js/gf-entries-table.js',
			[ 'jquery' ],
			PCPI_PGFT_VERSION,
			true
		);

		wp_localize_script(
			'pcpi-gf-entries-table',
			'PCPI_GF',
			[
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonceResend'  => wp_create_nonce( 'gf_resend_entry' ),
				'nonceDelete'  => wp_create_nonce( 'gf_delete_entry' ),
				'nonceSendPdf' => wp_create_nonce( 'pcpi_send_pdf_link' ),

				'confirmResend' => 'Resend link to applicant?',
				'confirmDelete' => "Permanently delete this applicant?\n\nThis will also delete any related:\n• Polygraph Questionnaire entries\n• Polygraph Questionnaire Review entries\n\nThis cannot be undone.",
			]
		);
	}

	private static function normalize_name( string $s ) : string {
		$s = trim( wp_strip_all_tags( $s ) );
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
		return $s;
	}

	private static function fetch_entries_paged( int $form_id, array $criteria, ?array $sorting = null, int $page_size = 200 ) {
		$all    = [];
		$offset = 0;

		while ( true ) {
			$paging = [ 'offset' => $offset, 'page_size' => $page_size ];
			$res    = GFAPI::get_entries( $form_id, $criteria, $sorting, $paging );

			if ( is_wp_error( $res ) ) {
				return $res;
			}

			if ( empty( $res ) ) {
				break;
			}

			$all    = array_merge( $all, $res );
			$offset = $offset + $page_size;

			if ( count( $res ) < $page_size ) {
				break;
			}
		}

		return $all;
	}

	/* -------------------------------------------------------------------------
	 * Pagination helpers (compact UI)
	 * ------------------------------------------------------------------------- */

	private static function get_current_page( string $page_var ) : int {
		$raw = isset( $_GET[ $page_var ] ) ? wp_unslash( $_GET[ $page_var ] ) : '';
		$p   = absint( $raw );
		return max( 1, $p );
	}

	private static function sanitize_page_var( string $page_var ) : string {
		$page_var = sanitize_key( $page_var );
		if ( $page_var === '' ) {
			$page_var = 'pcpi_page';
		}

		$reserved = [ 'p', 'page_id', 'attachment_id', 'name', 'post_type' ];
		if ( in_array( $page_var, $reserved, true ) ) {
			$page_var = 'pcpi_page';
		}

		return $page_var;
	}

	private static function render_pagination( int $current, int $total_pages, string $page_var ) : string {
		if ( $total_pages <= 1 ) {
			return '';
		}

		$base_url = remove_query_arg( $page_var );
		$mkurl    = function( int $p ) use ( $base_url, $page_var ) : string {
			return esc_url( add_query_arg( $page_var, $p, $base_url ) );
		};

		$window = 2;
		$start  = max( 1, $current - $window );
		$end    = min( $total_pages, $current + $window );

		$out  = '<nav class="pcpi-pagination" aria-label="Applicants pagination">';
		$out .= '<ul class="pcpi-pagination-list">';

		$out .= '<li class="pcpi-page-item' . ( $current > 1 ? '' : ' is-disabled' ) . '">';
		$out .= ( $current > 1 )
			? '<a href="' . $mkurl( $current - 1 ) . '" aria-label="Previous">«</a>'
			: '<span aria-hidden="true">«</span>';
		$out .= '</li>';

		for ( $p = $start; $p <= $end; $p++ ) {
			if ( $p === $current ) {
				$out .= '<li class="pcpi-page-item is-active"><span aria-current="page">' . esc_html( (string) $p ) . '</span></li>';
			} else {
				$out .= '<li class="pcpi-page-item"><a href="' . $mkurl( $p ) . '">' . esc_html( (string) $p ) . '</a></li>';
			}
		}

		$out .= '<li class="pcpi-page-item' . ( $current < $total_pages ? '' : ' is-disabled' ) . '">';
		$out .= ( $current < $total_pages )
			? '<a href="' . $mkurl( $current + 1 ) . '" aria-label="Next">»</a>'
			: '<span aria-hidden="true">»</span>';
		$out .= '</li>';

		$out .= '</ul></nav>';

		return $out;
	}

	/**
	 * Shortcode: [gf_entries_table]
	 */
	public static function shortcode_gf_entries_table( $atts ) : string {

		$a = shortcode_atts(
			[
				'form_id'          => 1,
				'last_name_field'  => '1.6',
				'first_name_field' => '1.3',
				'limit'            => 100,
				'page_var'         => 'pcpi_page',
				'debug'            => 'no',
			],
			(array) $atts,
			'gf_entries_table'
		);

		$debug    = ( strtolower( (string) $a['debug'] ) === 'yes' );
		$form_id  = absint( $a['form_id'] );
		$per_page = absint( $a['limit'] );
		if ( $per_page <= 0 ) {
			$per_page = 100;
		}

		$page_var = self::sanitize_page_var( (string) $a['page_var'] );
		$page     = self::get_current_page( $page_var );

		$cfg = pcpi_polygraph_gf_config();

		$PARENT_FORM_ID        = absint( $cfg['PARENT_FORM_ID'] );
		$REVIEW_FORM_ID        = absint( $cfg['REVIEW_FORM_ID'] );
		$REVIEW_FORM_IDS       = isset( $cfg['REVIEW_FORM_IDS'] ) && is_array( $cfg['REVIEW_FORM_IDS'] )
			? array_values( array_unique( array_map( 'absint', $cfg['REVIEW_FORM_IDS'] ) ) )
			: [ $REVIEW_FORM_ID ];
		$QUESTIONNAIRE_FORM_ID = absint( $cfg['QUESTIONNAIRE_FORM_ID'] );
		$PDF_ID                = (string) $cfg['PDF_ID'];

		$Q_PARENT_APPLICANT_EID_FID = (string) $cfg['Q_PARENT_APPLICANT_EID_FID'];
		$Q_REVIEW_DONE_FLAG_FID     = (string) $cfg['Q_REVIEW_DONE_FLAG_FID'];
		$REVIEW_PARENT_Q_EID_FID    = (string) $cfg['REVIEW_PARENT_Q_EID_FID'];
		$REVIEW_PARENT_A_EID_FID    = (string) $cfg['REVIEW_PARENT_A_EID_FID'];

		$AGENCY_NAME_FID  = (string) $cfg['AGENCY_NAME_FID'];
		$AGENCY_EMAIL_FID = (string) $cfg['AGENCY_EMAIL_FID'];

		$q_parent_field_ids        = array_values( array_unique( array_filter( [ $Q_PARENT_APPLICANT_EID_FID, '578', '579' ] ) ) );
		$review_parent_q_field_ids = array_values( array_unique( array_filter( [ $REVIEW_PARENT_Q_EID_FID, '607', '643' ] ) ) );

		if ( ! self::gf_is_available() ) {
			return '<p style="color:red;">Gravity Forms is not active.</p>';
		}

		self::maybe_enqueue_assets();

		$search_criteria = [ 'status' => 'active' ];
		$sorting         = [ 'key' => 'date_created', 'direction' => 'DESC' ];

		$total_entries = 0;
		if ( method_exists( 'GFAPI', 'count_entries' ) ) {
			$total_entries = GFAPI::count_entries( $form_id, $search_criteria );
			if ( is_wp_error( $total_entries ) ) {
				return '<p>Error counting entries: ' . esc_html( $total_entries->get_error_message() ) . '</p>';
			}
			$total_entries = absint( $total_entries );
		}

		$total_pages = ( $total_entries > 0 ) ? (int) ceil( $total_entries / $per_page ) : 1;
		$page        = min( max( 1, $page ), max( 1, $total_pages ) );

		$offset = ( $page - 1 ) * $per_page;
		$paging = [ 'offset' => $offset, 'page_size' => $per_page ];

		$entries = GFAPI::get_entries( $form_id, $search_criteria, $sorting, $paging );

		if ( is_wp_error( $entries ) ) {
			return '<p>Error fetching entries: ' . esc_html( $entries->get_error_message() ) . '</p>';
		}

		if ( empty( $entries ) ) {
			return '<p>All caught up!<br>No pending applicant comments to review.</p>';
		}

		static $cache_review_ready        = [];
		static $cache_q_entry_id          = [];
		static $cache_pdf_by_qeid         = [];
		static $cache_review_entry_by_qid = [];

		$html = '<table class="pcpi-gf-table">
			<thead>
				<tr>
					<th>Applicant</th>
					<th>Submitted</th>
					<th class="pcpi-gf-actions-col">Actions</th>
				</tr>
			</thead>
			<tbody>';

		$i = 0;

		foreach ( $entries as $e ) {

			$entry_id = absint( rgar( $e, 'id' ) ); // Form 1 entry id

			$last  = rgar( $e, $a['last_name_field'] ) ?: '—';
			$first = rgar( $e, $a['first_name_field'] ) ?: '—';

			$applicant_display = trim( $last . ', ' . $first );
			$applicant_name    = trim( $first . ' ' . $last );

			$date_created = rgar( $e, 'date_created' );
			$date         = $date_created ? wp_date( 'M j, Y g:i A', strtotime( $date_created ) ) : '—';

			$agency_name  = trim( (string) rgar( $e, $AGENCY_NAME_FID ) );
			$agency_email = trim( (string) rgar( $e, $AGENCY_EMAIL_FID ) );

			$review_btn  = '';
			$summary_btn = '';
			$sendpdf_btn = '';
			$debug_html  = '';

			/* REVIEW READY + Form 2 entry ID */
			$review_ready = false;
			$q_entry_id   = 0;

			if ( array_key_exists( $entry_id, $cache_review_ready ) ) {
				$review_ready = (bool) $cache_review_ready[ $entry_id ];
				$q_entry_id   = isset( $cache_q_entry_id[ $entry_id ] ) ? (int) $cache_q_entry_id[ $entry_id ] : 0;
			} else {
				$q_entries = [];

				foreach ( $q_parent_field_ids as $fid ) {
					$tmp = GFAPI::get_entries(
						$QUESTIONNAIRE_FORM_ID,
						[
							'status'        => 'active',
							'field_filters' => [
								[ 'key' => (string) $fid, 'value' => (string) $entry_id ],
							],
						],
						[ 'key' => 'date_created', 'direction' => 'DESC' ],
						[ 'page_size' => 1 ]
					);

					if ( ! is_wp_error( $tmp ) && ! empty( $tmp ) ) {
						$q_entries = $tmp;
						break;
					}
				}

				if ( ! empty( $q_entries ) ) {
					$q_entry_id   = (int) rgar( $q_entries[0], 'id' );
					$review_ready = ( rgar( $q_entries[0], $Q_REVIEW_DONE_FLAG_FID ) === '1' );
				}

				$cache_review_ready[ $entry_id ] = $review_ready;
				$cache_q_entry_id[ $entry_id ]   = $q_entry_id;
			}

			/* REVIEW BUTTON */
			if ( $review_ready && $q_entry_id > 0 ) {
				$review_url = add_query_arg(
					[
						'parent_questionnaire_entry_id' => $q_entry_id,
						'parent_applicant_entry_id'     => $entry_id,
					],
					site_url( '/form-polygraph-questionnaire-examiner-review/' )
				);

				$review_btn = '<a href="' . esc_url( $review_url ) . '" target="_blank" rel="noopener noreferrer" class="gf-action-btn pcpi-btn-review">Review</a>';
			} else {
				$review_btn = '<span class="gf-action-btn gf-disabled pcpi-btn-disabled" title="Review submitted questionnaire">Review</span>';
			}

			/* SUMMARY (PDF) + determine Review Entry ID */
			$pdf_ready       = false;
			$pdf_url         = '';
			$review_entry_id = 0;

			if ( $q_entry_id > 0 && array_key_exists( $q_entry_id, $cache_pdf_by_qeid ) ) {
				$pdf_url         = (string) $cache_pdf_by_qeid[ $q_entry_id ];
				$pdf_ready       = ( $pdf_url !== '' );
				$review_entry_id = isset( $cache_review_entry_by_qid[ $q_entry_id ] ) ? absint( $cache_review_entry_by_qid[ $q_entry_id ] ) : 0;
			} else {

				if ( $q_entry_id > 0 ) {

					$review_entries = [];

					foreach ( $REVIEW_FORM_IDS as $rid ) {
						foreach ( $review_parent_q_field_ids as $rfid ) {
							$tmp_reviews = GFAPI::get_entries(
								$rid,
								[
									'status'        => 'active',
									'field_filters' => [
										[ 'key' => (string) $rfid, 'value' => (string) $q_entry_id ],
									],
								],
								[ 'key' => 'date_created', 'direction' => 'DESC' ],
								[ 'page_size' => 1 ]
							);

							if ( ! is_wp_error( $tmp_reviews ) && ! empty( $tmp_reviews ) ) {
								$review_entries = $tmp_reviews;
								break 2;
							}
						}
					}

					if ( empty( $review_entries ) && $REVIEW_PARENT_A_EID_FID ) {
						foreach ( $REVIEW_FORM_IDS as $rid ) {
							$tmp_reviews = GFAPI::get_entries(
								$rid,
								[
									'status'        => 'active',
									'field_filters' => [
										[ 'key' => (string) $REVIEW_PARENT_A_EID_FID, 'value' => (string) $entry_id ],
									],
								],
								[ 'key' => 'date_created', 'direction' => 'DESC' ],
								[ 'page_size' => 1 ]
							);

							if ( ! is_wp_error( $tmp_reviews ) && ! empty( $tmp_reviews ) ) {
								$review_entries = $tmp_reviews;
								break;
							}
						}
					}

					if ( ! empty( $review_entries ) ) {
						$review_entry_id = absint( rgar( $review_entries[0], 'id' ) );

						$pdf_url = site_url(
							sprintf(
								'/pdf/%s/%d/',
								rawurlencode( (string) $PDF_ID ),
								$review_entry_id
							)
						);

						$pdf_ready = true;
					}
				}

				$cache_pdf_by_qeid[ $q_entry_id ]         = $pdf_ready ? $pdf_url : '';
				$cache_review_entry_by_qid[ $q_entry_id ] = $review_entry_id;
			}

			if ( $pdf_ready && $pdf_url !== '' ) {
				$summary_btn = '<a href="' . esc_url( $pdf_url ) . '" target="_blank" rel="noopener noreferrer" class="gf-action-btn pcpi-btn-summary">Summary</a>';
			} else {
				$summary_btn = '<span class="gf-action-btn gf-disabled pcpi-btn-disabled" title="PDF will be available after review is submitted">Summary</span>';
			}

			/* SEND PDF BUTTON (opens modal, uses signed link email) */
			if ( $pdf_ready && $review_entry_id > 0 ) {
				$sendpdf_btn = '<button type="button"
					class="gf-action-btn pcpi-btn-sendpdf pcpi-gf-sendpdf"
					data-applicant-id="' . esc_attr( $entry_id ) . '"
					data-review-id="' . esc_attr( $review_entry_id ) . '"
					data-agency-email="' . esc_attr( $agency_email ) . '"
					data-agency-name="' . esc_attr( $agency_name ) . '"
					data-applicant-name="' . esc_attr( $applicant_name ) . '"
				>Send PDF</button>';
			} else {
				$sendpdf_btn = '<span class="gf-action-btn gf-disabled pcpi-btn-disabled" title="Send is available after PDF is ready">Send PDF</span>';
			}

			if ( $debug ) {
				$debug_lines   = [];
				$debug_lines[] = 'applicant_entry_id=' . (string) $entry_id;
				$debug_lines[] = 'q_entry_id=' . (string) $q_entry_id;
				$debug_lines[] = 'review_entry_id=' . (string) $review_entry_id;
				$debug_lines[] = 'review_ready=' . ( $review_ready ? 'true' : 'false' );
				$debug_lines[] = 'pdf_ready=' . ( $pdf_ready ? 'true' : 'false' );
				$debug_lines[] = 'pdf_url=' . ( $pdf_url ?: '' );

				$debug_html = '<div class="pcpi-debug">' . implode( '<br>', array_map( 'esc_html', $debug_lines ) ) . '</div>';
			}

			$actions = '
				<div class="pcpi-actions">
					' . $review_btn . '
					' . $summary_btn . '
					' . $sendpdf_btn . '
					<button type="button" class="gf-action-btn pcpi-btn-resend pcpi-gf-resend-entry" data-id="' . esc_attr( $entry_id ) . '">Resend</button>
					<button type="button" class="gf-action-btn pcpi-btn-delete pcpi-gf-delete-entry" data-id="' . esc_attr( $entry_id ) . '">Delete</button>
				</div>
				' . $debug_html;

			$bg_class = ( $i++ % 2 ) ? 'pcpi-row-odd' : 'pcpi-row-even';

			$html .= '<tr class="' . esc_attr( $bg_class ) . '">
				<td>' . esc_html( $applicant_display ) . '</td>
				<td>' . esc_html( $date ) . '</td>
				<td class="pcpi-actions-td">' . $actions . '</td>
			</tr>';
		}

		$html .= '</tbody></table>';

		if ( $total_entries > 0 ) {
			$html .= self::render_pagination( $page, $total_pages, $page_var );
		}

		return $html;
	}

	/**
	 * AJAX: SEND PDF LINK (signed link emailed to agency)
	 *
	 * POST:
	 * - applicant_entry_id
	 * - review_entry_id (Form 23 entry id)
	 * - to_name (optional override)
	 * - to_email (optional override)
	 */
	public static function ajax_pcpi_send_pdf_link() : void {

		check_ajax_referer( 'pcpi_send_pdf_link', 'security' );

		if ( ! self::user_can_manage() ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
		}

		if ( ! class_exists( 'GFAPI' ) ) {
			wp_send_json_error( [ 'message' => 'Gravity Forms not available.' ] );
		}

		$cfg = pcpi_polygraph_gf_config();

		$PARENT_FORM_ID   = absint( $cfg['PARENT_FORM_ID'] );
		$AGENCY_NAME_FID  = (string) $cfg['AGENCY_NAME_FID'];
		$AGENCY_EMAIL_FID = (string) $cfg['AGENCY_EMAIL_FID'];

		$applicant_entry_id = isset( $_POST['applicant_entry_id'] ) ? absint( $_POST['applicant_entry_id'] ) : 0;
		$review_entry_id    = isset( $_POST['review_entry_id'] ) ? absint( $_POST['review_entry_id'] ) : 0;

		$to_name_override  = isset( $_POST['to_name'] ) ? sanitize_text_field( wp_unslash( $_POST['to_name'] ) ) : '';
		$to_email_override = isset( $_POST['to_email'] ) ? sanitize_text_field( wp_unslash( $_POST['to_email'] ) ) : '';

		if ( ! $applicant_entry_id || ! $review_entry_id ) {
			wp_send_json_error( [ 'message' => 'Missing applicant_entry_id or review_entry_id.' ] );
		}

		$app = GFAPI::get_entry( $applicant_entry_id );
		if ( is_wp_error( $app ) || empty( $app ) ) {
			wp_send_json_error( [ 'message' => 'Applicant entry not found.' ] );
		}

		if ( absint( rgar( $app, 'form_id' ) ) !== $PARENT_FORM_ID ) {
			wp_send_json_error( [ 'message' => 'Applicant entry must be Form 1.' ] );
		}

		$agency_name  = trim( (string) rgar( $app, $AGENCY_NAME_FID ) );
		$agency_email = trim( (string) rgar( $app, $AGENCY_EMAIL_FID ) );

		$to_name  = ( $to_name_override !== '' ) ? $to_name_override : $agency_name;
		$to_email = ( $to_email_override !== '' ) ? $to_email_override : $agency_email;
		$to_email = trim( (string) $to_email );

		if ( $to_email === '' || ! is_email( $to_email ) ) {
			wp_send_json_error( [ 'message' => 'Agency email is missing or invalid.' ] );
		}

		// Build signed PDF download link
$link = pcpi_build_secure_pdf_link( $review_entry_id );

// Applicant name (Form 1)
$first = trim( (string) rgar( $app, '1.3' ) );
$last  = trim( (string) rgar( $app, '1.6' ) );
$applicant_name = trim( $first . ' ' . $last );

// TTL in days (for the email text)
$ttl_days = isset( $cfg['PDF_LINK_TTL_DAYS'] ) ? absint( $cfg['PDF_LINK_TTL_DAYS'] ) : 7;
if ( $ttl_days <= 0 ) {
	$ttl_days = 7;
}
$expires_text = ( $ttl_days === 1 ) ? '24 hours' : (string) $ttl_days . ' days';

// Subject
$subject = 'Polygraph Questionnaire' . ( $applicant_name ? ' – ' . $applicant_name : '' );

// HTML email body with a clickable link
$body  = 'Hello' . ( $to_name ? ' ' . esc_html( $to_name ) : '' ) . ',<br><br>';
$body .= 'Here is the completed polygraph questionnaire for <strong>' . esc_html( $applicant_name ?: 'the applicant' ) . '</strong>: ';
$body .= '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">completed polygraph questionnaire</a>.<br><br>';
$body .= 'This secure link will expire in <strong>' . esc_html( $expires_text ) . '</strong>.<br><br>';
$body .= '— Pacific Coast Polygraph &amp; Investigations';

// Headers (send HTML)
$headers   = [];
$headers[] = 'Content-Type: text/html; charset=UTF-8';

$sent = wp_mail( $to_email, $subject, $body, $headers );


		if ( ! $sent ) {
			wp_send_json_error( [ 'message' => 'Email failed to send.' ] );
		}

		wp_send_json_success( [ 'message' => 'Sent.' ] );
	}

	/**
	 * AJAX: RESEND
	 */
	public static function ajax_gf_resend_entry() : void {

		check_ajax_referer( 'gf_resend_entry', 'security' );

		if ( ! self::user_can_manage() ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
		}

		if ( ! class_exists( 'GFAPI' ) ) {
			wp_send_json_error( [ 'message' => 'Gravity Forms not available.' ] );
		}

		$cfg = pcpi_polygraph_gf_config();
		$PARENT_FORM_ID = absint( $cfg['PARENT_FORM_ID'] );

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		if ( ! $entry_id ) {
			wp_send_json_error( [ 'message' => 'Missing entry_id.' ] );
		}

		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || empty( $entry ) ) {
			wp_send_json_error( [ 'message' => 'Applicant entry not found.' ] );
		}

		$form_id = absint( rgar( $entry, 'form_id' ) );
		if ( $form_id !== $PARENT_FORM_ID ) {
			wp_send_json_error( [ 'message' => 'Resend must be called with an Applicant (Form 1) entry.' ] );
		}

		$form = GFAPI::get_form( $form_id );
		if ( empty( $form ) || is_wp_error( $form ) ) {
			wp_send_json_error( [ 'message' => 'Could not load form.' ] );
		}

		$target_name      = isset( $cfg['RESEND_NOTIFICATION_NAME'] ) ? (string) $cfg['RESEND_NOTIFICATION_NAME'] : 'Questionnaire link';
		$target_name_norm = self::normalize_name( $target_name );

		$sent = false;

		if ( method_exists( 'GFAPI', 'send_notifications' ) && ! empty( $form['notifications'] ) && is_array( $form['notifications'] ) ) {
			foreach ( $form['notifications'] as $nid => $n ) {
				$n_name = isset( $n['name'] ) ? (string) $n['name'] : '';
				if ( $n_name !== '' && self::normalize_name( $n_name ) === $target_name_norm ) {
					$res  = GFAPI::send_notifications( [ (string) $nid ], $form, $entry );
					$sent = ( $res !== false );
					break;
				}
			}
		}

		if ( ! $sent && class_exists( 'GFCommon' ) ) {
			GFCommon::send_form_submission_notifications( $form, $entry );
			$sent = true;
		}

		if ( ! $sent ) {
			wp_send_json_error( [ 'message' => 'Notification did not send.' ] );
		}

		wp_send_json_success( [ 'message' => 'Sent.' ] );
	}

	/**
	 * AJAX: DELETE (cascade)
	 */
	public static function ajax_gf_delete_entry() : void {

		check_ajax_referer( 'gf_delete_entry', 'security' );

		if ( ! self::user_can_manage() ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
		}

		if ( ! class_exists( 'GFAPI' ) ) {
			wp_send_json_error( [ 'message' => 'Gravity Forms not available.' ] );
		}

		$cfg = pcpi_polygraph_gf_config();

		$PARENT_FORM_ID        = absint( $cfg['PARENT_FORM_ID'] );
		$QUESTIONNAIRE_FORM_ID = absint( $cfg['QUESTIONNAIRE_FORM_ID'] );
		$REVIEW_FORM_ID        = absint( $cfg['REVIEW_FORM_ID'] );
		$REVIEW_FORM_IDS       = isset( $cfg['REVIEW_FORM_IDS'] ) && is_array( $cfg['REVIEW_FORM_IDS'] )
			? array_values( array_unique( array_map( 'absint', $cfg['REVIEW_FORM_IDS'] ) ) )
			: [ $REVIEW_FORM_ID ];

		$Q_PARENT_APPLICANT_EID_FID = (string) $cfg['Q_PARENT_APPLICANT_EID_FID'];
		$REVIEW_PARENT_Q_EID_FID    = (string) $cfg['REVIEW_PARENT_Q_EID_FID'];
		$REVIEW_PARENT_A_EID_FID    = isset( $cfg['REVIEW_PARENT_A_EID_FID'] ) ? (string) $cfg['REVIEW_PARENT_A_EID_FID'] : '608';

		$q_parent_field_ids        = array_values( array_unique( array_filter( [ $Q_PARENT_APPLICANT_EID_FID, '578', '579' ] ) ) );
		$review_parent_q_field_ids = array_values( array_unique( array_filter( [ $REVIEW_PARENT_Q_EID_FID, '607', '643' ] ) ) );

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		if ( ! $entry_id ) {
			wp_send_json_error( [ 'message' => 'Missing entry_id.' ] );
		}

		$parent_entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $parent_entry ) || empty( $parent_entry ) ) {
			wp_send_json_error( [ 'message' => 'Parent entry not found.' ] );
		}

		$parent_form_id = absint( rgar( $parent_entry, 'form_id' ) );

		if ( $parent_form_id !== $PARENT_FORM_ID ) {
			$result = GFAPI::delete_entry( $entry_id );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( [ 'message' => 'Delete failed.' ] );
			}
			wp_send_json_success( [ 'deleted_parent' => 1, 'deleted_questionnaire' => 0, 'deleted_review' => 0 ] );
		}

		$deleted_parent        = 0;
		$deleted_questionnaire = 0;
		$deleted_review        = 0;

		$q_entries = [];
		foreach ( $q_parent_field_ids as $fid ) {
			$tmp = self::fetch_entries_paged(
				$QUESTIONNAIRE_FORM_ID,
				[
					'status'        => 'active',
					'field_filters' => [
						[ 'key' => (string) $fid, 'value' => (string) $entry_id ],
					],
				],
				[ 'key' => 'date_created', 'direction' => 'DESC' ]
			);

			if ( is_wp_error( $tmp ) ) {
				wp_send_json_error( [ 'message' => 'Error looking up questionnaire entries.' ] );
			}

			if ( ! empty( $tmp ) ) {
				$q_entries = $tmp;
				break;
			}
		}

		if ( empty( $q_entries ) ) {

			$all_q = self::fetch_entries_paged(
				$QUESTIONNAIRE_FORM_ID,
				[ 'status' => 'active' ],
				[ 'key' => 'date_created', 'direction' => 'DESC' ]
			);

			if ( is_wp_error( $all_q ) ) {
				wp_send_json_error( [ 'message' => 'Error scanning questionnaire entries (fallback).' ] );
			}

			$q_entries = array_values(
				array_filter(
					$all_q,
					function( $q ) use ( $q_parent_field_ids, $entry_id ) : bool {
						foreach ( $q_parent_field_ids as $fid ) {
							if ( (string) rgar( $q, (string) $fid ) === (string) $entry_id ) {
								return true;
							}
						}
						return false;
					}
				)
			);
		}

		foreach ( $q_entries as $q ) {

			$q_entry_id = absint( rgar( $q, 'id' ) );
			if ( ! $q_entry_id ) {
				continue;
			}

			$review_entries = [];

			foreach ( $REVIEW_FORM_IDS as $rid ) {
				foreach ( $review_parent_q_field_ids as $rfid ) {

					$tmp_reviews = self::fetch_entries_paged(
						$rid,
						[
							'status'        => 'active',
							'field_filters' => [
								[ 'key' => (string) $rfid, 'value' => (string) $q_entry_id ],
							],
						],
						[ 'key' => 'date_created', 'direction' => 'DESC' ]
					);

					if ( is_wp_error( $tmp_reviews ) ) {
						wp_send_json_error( [ 'message' => 'Error looking up review entries.' ] );
					}

					if ( ! empty( $tmp_reviews ) ) {
						$review_entries = $tmp_reviews;
						break 2;
					}
				}
			}

			if ( empty( $review_entries ) && $REVIEW_PARENT_A_EID_FID ) {
				foreach ( $REVIEW_FORM_IDS as $rid ) {
					$tmp_reviews = self::fetch_entries_paged(
						$rid,
						[
							'status'        => 'active',
							'field_filters' => [
								[ 'key' => (string) $REVIEW_PARENT_A_EID_FID, 'value' => (string) $entry_id ],
							],
						],
						[ 'key' => 'date_created', 'direction' => 'DESC' ]
					);

					if ( is_wp_error( $tmp_reviews ) ) {
						wp_send_json_error( [ 'message' => 'Error looking up review entries (fallback by applicant).' ] );
					}

					if ( ! empty( $tmp_reviews ) ) {
						$review_entries = $tmp_reviews;
						break;
					}
				}
			}

			foreach ( $review_entries as $re ) {
				$review_entry_id = absint( rgar( $re, 'id' ) );
				if ( ! $review_entry_id ) {
					continue;
				}

				$del = GFAPI::delete_entry( $review_entry_id );
				if ( is_wp_error( $del ) ) {
					wp_send_json_error( [ 'message' => 'Failed deleting a review entry.' ] );
				}
				$deleted_review++;
			}

			$del_q = GFAPI::delete_entry( $q_entry_id );
			if ( is_wp_error( $del_q ) ) {
				wp_send_json_error( [ 'message' => 'Failed deleting a questionnaire entry.' ] );
			}
			$deleted_questionnaire++;
		}

		if ( empty( $q_entries ) && $REVIEW_PARENT_A_EID_FID ) {

			$review_entries = [];

			foreach ( $REVIEW_FORM_IDS as $rid ) {

				$tmp_reviews = self::fetch_entries_paged(
					$rid,
					[
						'status'        => 'active',
						'field_filters' => [
							[ 'key' => (string) $REVIEW_PARENT_A_EID_FID, 'value' => (string) $entry_id ],
						],
					],
					[ 'key' => 'date_created', 'direction' => 'DESC' ]
				);

				if ( is_wp_error( $tmp_reviews ) ) {
					wp_send_json_error( [ 'message' => 'Error looking up review entries (by applicant).' ] );
				}

				if ( ! empty( $tmp_reviews ) ) {
					$review_entries = $tmp_reviews;
					break;
				}
			}

			foreach ( $review_entries as $re ) {
				$review_entry_id = absint( rgar( $re, 'id' ) );
				if ( ! $review_entry_id ) {
					continue;
				}

				$del = GFAPI::delete_entry( $review_entry_id );
				if ( is_wp_error( $del ) ) {
					wp_send_json_error( [ 'message' => 'Failed deleting a review entry (by applicant).' ] );
				}
				$deleted_review++;
			}
		}

		$del_parent = GFAPI::delete_entry( $entry_id );
		if ( is_wp_error( $del_parent ) ) {
			wp_send_json_error( [ 'message' => 'Failed deleting applicant entry.' ] );
		}

		$deleted_parent = 1;

		wp_send_json_success(
			[
				'deleted_parent'        => $deleted_parent,
				'deleted_questionnaire' => $deleted_questionnaire,
				'deleted_review'        => $deleted_review,
			]
		);
	}
}

PCPI_Polygraph_GF_Tools::init();

/* =============================================================================
 * Secure questionnaire link (existing)
 * ============================================================================= */

/**
 * Build a secure, signed questionnaire link for a given Applicant (Form 1) entry ID.
 */
function pcpi_build_secure_questionnaire_link( int $parent_applicant_entry_id ) : string {

	$path = '/form-polygraph-questionaire/';

	$ttl_days = 30;
	if ( class_exists( 'PCPI_Site_Access_Control' ) && defined( 'PCPI_Site_Access_Control::LINK_TTL_DAYS' ) ) {
		$ttl_days = (int) constant( 'PCPI_Site_Access_Control::LINK_TTL_DAYS' );
		if ( $ttl_days <= 0 ) {
			$ttl_days = 30;
		}
	}

	$exp   = time() + ( $ttl_days * DAY_IN_SECONDS );
	$nonce = bin2hex( random_bytes( 16 ) );

	$args = [
		'parent_applicant_entry_id' => $parent_applicant_entry_id,
		'pcpi_exp'                  => $exp,
		'pcpi_nonce'                => $nonce,
	];

	ksort( $args );
	$base = $path . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );

	$sig = hash_hmac( 'sha256', $base, wp_salt( 'pcpi_polygraph_links' ) );

	$args['pcpi_sig'] = $sig;

	return site_url( $path . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ) );
}

/**
 * Custom merge tag replacement:
 * Use {pcpi_questionnaire_link} in Form 1 notifications.
 */
add_filter(
	'gform_replace_merge_tags',
	function( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {

		$form_id = isset( $form['id'] ) ? absint( $form['id'] ) : 0;
		if ( $form_id !== 1 ) {
			return $text;
		}

		if ( strpos( $text, '{pcpi_questionnaire_link}' ) === false ) {
			return $text;
		}

		$entry_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
		if ( ! $entry_id ) {
			return str_replace( '{pcpi_questionnaire_link}', '', $text );
		}

		$link = pcpi_build_secure_questionnaire_link( $entry_id );

		return str_replace( '{pcpi_questionnaire_link}', $link, $text );
	},
	10,
	7
);

/* =============================================================================
 * Secure PDF link + download endpoint (Option A: generate/stream privately)
 * ============================================================================= */

/**
 * Build a secure, signed PDF download link for a given Review (Form 23) entry ID.
 * Link hits front-end endpoint: /?pcpi_pdf_dl=1&rid=...&pcpi_exp=...&pcpi_nonce=...&pcpi_sig=...
 */
function pcpi_build_secure_pdf_link( int $review_entry_id ) : string {

	$cfg      = pcpi_polygraph_gf_config();
	$ttl_days = isset( $cfg['PDF_LINK_TTL_DAYS'] ) ? absint( $cfg['PDF_LINK_TTL_DAYS'] ) : 7;
	if ( $ttl_days <= 0 ) {
		$ttl_days = 7;
	}

	$exp   = time() + ( $ttl_days * DAY_IN_SECONDS );
	$nonce = bin2hex( random_bytes( 16 ) );

	$args = [
		'pcpi_pdf_dl' => 1,
		'rid'         => $review_entry_id,
		'pcpi_exp'    => $exp,
		'pcpi_nonce'  => $nonce,
	];

	ksort( $args );

	$base = '/?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	$sig  = hash_hmac( 'sha256', $base, wp_salt( 'pcpi_polygraph_links' ) );

	$args['pcpi_sig'] = $sig;

	return site_url( '/?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ) );
}

add_filter(
	'query_vars',
	function( $vars ) {
		$vars[] = 'pcpi_pdf_dl';
		$vars[] = 'rid';
		$vars[] = 'pcpi_exp';
		$vars[] = 'pcpi_nonce';
		$vars[] = 'pcpi_sig';
		return $vars;
	}
);

/**
 * Secure PDF endpoint:
 * - validates signature + expiry
 * - generates PDF via Gravity PDF API (private; no public /pdf/ access needed)
 * - streams bytes to browser
 */
add_action(
	'template_redirect',
	function() {

		$dl = get_query_var( 'pcpi_pdf_dl' );
		if ( (string) $dl !== '1' ) {
			return;
		}

		// Prevent PHP notices/warnings from corrupting the PDF output.
		@ini_set( 'display_errors', '0' );
		@ini_set( 'html_errors', '0' );

		$rid   = absint( get_query_var( 'rid' ) );
		$exp   = absint( get_query_var( 'pcpi_exp' ) );
		$nonce = (string) get_query_var( 'pcpi_nonce' );
		$sig   = (string) get_query_var( 'pcpi_sig' );

		if ( ! $rid || ! $exp || $nonce === '' || $sig === '' ) {
			wp_die( 'Invalid link.', 400 );
		}

		if ( time() > $exp ) {
			wp_die( 'This link has expired.', 403 );
		}

		// Rebuild the signed base exactly.
		$args = [
			'pcpi_pdf_dl' => 1,
			'rid'         => $rid,
			'pcpi_exp'    => $exp,
			'pcpi_nonce'  => $nonce,
		];
		ksort( $args );

		$base     = '/?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
		$expected = hash_hmac( 'sha256', $base, wp_salt( 'pcpi_polygraph_links' ) );

		if ( ! hash_equals( $expected, $sig ) ) {
			wp_die( 'Invalid signature.', 403 );
		}

		$cfg    = pcpi_polygraph_gf_config();
		$pdf_id = isset( $cfg['PDF_ID'] ) ? (string) $cfg['PDF_ID'] : '';

		if ( $pdf_id === '' ) {
			wp_die( 'PDF configuration missing.', 500 );
		}

		// Gravity PDF API check.
		if ( ! class_exists( 'GPDFAPI' ) ) {
			wp_die( 'Gravity PDF API not available.', 500 );
		}

		/**
		 * Gravity PDF v6:
		 * create_pdf( $entry_id, $pdf_id ) returns a file path or WP_Error.
		 */
		$pdf_path = GPDFAPI::create_pdf( $rid, $pdf_id );

		if ( is_wp_error( $pdf_path ) || ! is_string( $pdf_path ) || $pdf_path === '' || ! is_file( $pdf_path ) ) {
			wp_die( 'PDF not available.', 404 );
		}

		// Clear any buffered output before sending headers/PDF bytes.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="polygraph-report-' . $rid . '.pdf"' );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		// Stream file.
		$fp = fopen( $pdf_path, 'rb' );
		if ( $fp ) {
			fpassthru( $fp );
			fclose( $fp );
		}

		// Cleanup temp PDF (Gravity PDF docs recommend unlinking).
		@unlink( $pdf_path );

		exit;
	}
);