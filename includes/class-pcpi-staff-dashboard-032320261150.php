<?php

/**
 * Main controller for the PCPI Staff Dashboard.
 *
 * Responsibilities
 * - Registers the [gf_entries_table] shortcode.
 * - Queries Gravity Forms entries and renders the dashboard table.
 * - Implements server-side pagination.
 * - Enqueues frontend assets early enough for CSS to load.
 * - Handles AJAX actions (resend, delete/cascade, send PDF link).
 *
 * Back-compat
 * - Older builds used the class name PCPI_Polygraph_GF_Tools.
 *   We provide a class_alias at the bottom of this file.
 */
final class PCPI_Staff_Dashboard {

	private static bool $assets_enqueued = false;

	public static function init() : void {
		add_shortcode( 'gf_entries_table', [ __CLASS__, 'shortcode_gf_entries_table' ] );


		// Enqueue frontend assets early enough for CSS (shortcode is rendered after wp_head).
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_frontend_assets' ] );
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
	
	// NEW: Staff help URL (filterable)
	private static function get_staff_help_url() : string {
		$default = site_url( '/staff-help/' );
		$url     = apply_filters( 'pcpi_staff_help_url', $default );
		return esc_url( (string) $url );
	}

/**
 * Build the review URL (new engine route).
 *
 * IMPORTANT:
 * - The Review POC expects entry_id to be the Applicant entry (Form 1).
 * - We also pass q_entry_id for the Questionnaire entry for lookups.
 */
private static function build_review_url( array $workflow, string $workflow_key, int $applicant_entry_id, int $questionnaire_entry_id ) : string {
	$path = isset( $workflow['review_page_path'] ) ? (string) $workflow['review_page_path'] : '/review/';
	$base = home_url( $path );

	return add_query_arg(
		[
			'workflow'   => $workflow_key,
			'entry_id'   => (int) $applicant_entry_id,
			'q_entry_id' => (int) $questionnaire_entry_id,
		],
		$base
	);
}
/**
 * Find Questionnaire field IDs that store the parent applicant entry id.
 * Priority:
 *  1) Config Q_PARENT_APPLICANT_EID_FID (if set)
 *  2) Field "Parameter Name" (inputName) == parent_applicant_entry_id
 *  3) CSS class contains pcpi-parent-applicant
 */
private static function get_questionnaire_parent_field_ids( int $questionnaire_form_id, array $cfg ) : array {
	$ids = [];

	$cfg_id = isset( $cfg['Q_PARENT_APPLICANT_EID_FID'] ) ? trim( (string) $cfg['Q_PARENT_APPLICANT_EID_FID'] ) : '';
	if ( $cfg_id !== '' ) {
		$ids[] = $cfg_id;
		return $ids;
	}

	if ( ! class_exists( 'GFAPI' ) ) {
		return $ids;
	}

	$form = GFAPI::get_form( $questionnaire_form_id );
	if ( empty( $form['fields'] ) ) {
		return $ids;
	}

	foreach ( $form['fields'] as $field ) {
		if ( ! is_object( $field ) ) {
			continue;
		}

		// 1) Parameter Name on a hidden field (recommended)
		if ( property_exists( $field, 'inputName' ) && (string) $field->inputName === 'parent_applicant_entry_id' ) {
			$ids[] = (string) $field->id;
			continue;
		}

		// 2) CSS class fallback
		$css = property_exists( $field, 'cssClass' ) ? (string) $field->cssClass : '';
		if ( $css && stripos( $css, 'pcpi-parent-applicant' ) !== false ) {
			$ids[] = (string) $field->id;
			continue;
		}

		// 3) Field label / adminLabel fallback (helps on fresh test installs)
		$label     = property_exists( $field, 'label' ) ? (string) $field->label : '';
		$admin     = property_exists( $field, 'adminLabel' ) ? (string) $field->adminLabel : '';
		$is_hidden = property_exists( $field, 'type' ) && (string) $field->type === 'hidden';

		if ( $is_hidden ) {
			if ( $admin && sanitize_key( $admin ) === 'parent_applicant_entry_id' ) {
				$ids[] = (string) $field->id;
				continue;
			}
			if ( $label && stripos( $label, 'Parent Applicant Entry ID' ) !== false ) {
				$ids[] = (string) $field->id;
				continue;
			}
		}
	}

	return array_values( array_unique( array_filter( $ids ) ) );
}


/**
 * Get Applicant first/last name.
 * Uses shortcode-provided field ids first; falls back to the first Name field on the form.
 */
private static function get_entry_first_last( int $form_id, array $entry, string $first_id, string $last_id ) : array {

	$first = $first_id !== '' ? trim( (string) rgar( $entry, $first_id ) ) : '';
	$last  = $last_id !== '' ? trim( (string) rgar( $entry, $last_id ) ) : '';

	if ( $first !== '' || $last !== '' ) {
		return [ $first, $last ];
	}

	if ( ! class_exists( 'GFAPI' ) ) {
		return [ $first, $last ];
	}

	$form = GFAPI::get_form( $form_id );
	if ( empty( $form['fields'] ) ) {
		return [ $first, $last ];
	}

	foreach ( $form['fields'] as $field ) {
		if ( is_object( $field ) && (string) $field->type === 'name' && ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
			$fid_first = (string) $field->id . '.3';
			$fid_last  = (string) $field->id . '.6';
			$first = trim( (string) rgar( $entry, $fid_first ) );
			$last  = trim( (string) rgar( $entry, $fid_last ) );
			return [ $first, $last ];
		}
	}

	return [ $first, $last ];
}

	/**
 * Resolve the workflow key for an Applicant entry.
 *
 * Single source of truth:
 * - Prefer Workflow Engine registry (workflow field id per workflow).
 * - Fallback to configured Applicant workflow field id (legacy config).
 * - Fallback to DEFAULT_WORKFLOW_KEY, then 'polygraph'.
 *
 * @param array<string,mixed> $entry Applicant entry.
 * @param array<string,mixed> $cfg   Optional config (pcpi_polygraph_gf_config()).
 */
private static function resolve_workflow_key_from_entry( array $entry, array $cfg = [] ): string {

	// 0) Hidden field with admin label "workflow_key" on the entry's form (supports questionnaire-root kiosk workflows).
	static $workflow_key_field_cache = [];
	$form_id = absint( rgar( $entry, 'form_id' ) );
	if ( $form_id > 0 && class_exists( 'GFAPI' ) ) {
		if ( ! array_key_exists( $form_id, $workflow_key_field_cache ) ) {
			$fid = 0;
			$form = GFAPI::get_form( $form_id );
			if ( ! empty( $form['fields'] ) && is_array( $form['fields'] ) ) {
				foreach ( $form['fields'] as $field ) {
					if ( ! is_object( $field ) ) { continue; }
					$type  = property_exists( $field, 'type' ) ? (string) $field->type : '';
					$admin = property_exists( $field, 'adminLabel' ) ? sanitize_key( (string) $field->adminLabel ) : '';
					$label = property_exists( $field, 'label' ) ? sanitize_key( (string) $field->label ) : '';

					// Prefer Admin Label, but fall back to Field Label for Hidden fields
					// because GF Hidden fields often end up with only a Field Label set.
					if ( $type === 'hidden' && ( $admin === 'workflow_key' || $label === 'workflow_key' ) ) {
						$fid = absint( $field->id );
						break;
					}
				}
			}
			$workflow_key_field_cache[ $form_id ] = $fid;
		}
		$fid = absint( $workflow_key_field_cache[ $form_id ] );
		if ( $fid > 0 ) {
			$raw = trim( (string) rgar( $entry, (string) $fid ) );
			if ( $raw !== '' ) {
				return sanitize_key( $raw );
			}
		}
	}


	// 1) Workflow Engine registry (preferred).
	if ( class_exists( 'PCPI_Workflow_Engine' ) && method_exists( 'PCPI_Workflow_Engine', 'get_workflows' ) ) {
		$workflows = (array) PCPI_Workflow_Engine::get_workflows();
		foreach ( $workflows as $key => $wf ) {
			$wf  = (array) $wf;
			$fid = isset( $wf['applicant_workflow_field_id'] ) ? absint( $wf['applicant_workflow_field_id'] ) : 0;
			if ( $fid <= 0 ) {
				continue;
			}
			$raw = trim( (string) rgar( $entry, (string) $fid ) );
			if ( $raw !== '' ) {
				return sanitize_key( $raw );
			}
		}

		// Context fallback (useful inside workflow-specific pages).
		if ( method_exists( 'PCPI_Workflow_Engine', 'context' ) ) {
			$ctx = (array) PCPI_Workflow_Engine::context();
			if ( ! empty( $ctx['workflow_key'] ) ) {
				return sanitize_key( (string) $ctx['workflow_key'] );
			}
		}
	}

	// 2) Legacy config fallback (Applicant entry stores workflow key in a field).
	$fid = isset( $cfg['APPLICANT_WORKFLOW_FID'] ) ? (string) $cfg['APPLICANT_WORKFLOW_FID'] : '';
	if ( $fid !== '' ) {
		$raw = trim( (string) rgar( $entry, $fid ) );
		if ( $raw !== '' ) {
			return sanitize_key( $raw );
		}
	}

	// 3) Default key fallback.
	$default = isset( $cfg['DEFAULT_WORKFLOW_KEY'] ) ? (string) $cfg['DEFAULT_WORKFLOW_KEY'] : 'polygraph';
	return sanitize_key( $default !== '' ? $default : 'polygraph' );
}

/**
 * Enqueue frontend assets for the Staff Dashboard table.
 *
 * IMPORTANT: Must run on `wp_enqueue_scripts` so the stylesheet is printed in wp_head.
 * The shortcode renders after wp_head, so enqueueing inside the shortcode will miss CSS.
 */
public static function enqueue_frontend_assets() : void {

    // Only staff or admins should get these assets.
    $uid = get_current_user_id();

    $is_staff = function_exists( 'pcpi_saux_is_staff_user' )
        ? pcpi_saux_is_staff_user( $uid )
        : in_array( 'staff', (array) wp_get_current_user()->roles, true );

    $is_admin = current_user_can( 'manage_options' );

    if ( ! $is_staff && ! $is_admin ) {
        return;
    }

    // Only enqueue on pages where the shortcode exists (avoids loading sitewide).
    if ( function_exists( 'is_singular' ) && is_singular() ) {
        global $post;
        if ( empty( $post ) || ! isset( $post->post_content ) || ! has_shortcode( (string) $post->post_content, 'gf_entries_table' ) ) {
            return;
        }
    } else {
        // If it's not a singular post/page (archives, etc.), don't enqueue.
        return;
    }

    if ( self::$assets_enqueued ) {
        return;
    }
    self::$assets_enqueued = true;

    wp_enqueue_style( 'dashicons' );

    wp_enqueue_style(
        'pcpi-gf-entries-table',
        PCPI_SD_URL . 'assets/css/gf-entries-table.css',
        [],
        PCPI_SD_VERSION
    );

    wp_enqueue_script( 'jquery' );

    wp_enqueue_script(
        'pcpi-gf-entries-table',
        PCPI_SD_URL . 'assets/js/gf-entries-table.js',
        [ 'jquery' ],
        PCPI_SD_VERSION,
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

        'addApplicantBtnSelector'    => '.pcpi-add-applicant-btn',
        'addApplicantFormId'         => 1,
        'addApplicantDisableMobile'  => true,
        'addApplicantMobileMaxWidth' => 782,

        'confirmResend' => 'Resend link to applicant?',
        'confirmDelete' => "Permanently delete this applicant?

This will also delete any related:
• Questionnaire entries
• Questionnaire Review entries

This cannot be undone.",
    ]
);
}


	private static function normalize_name( string $s ) : string {
		$s = trim( wp_strip_all_tags( $s ) );
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
		return $s;
	}


	// NEW: Attempt to detect the Applicant email field ID from the Form 1 form object.
	// Falls back to 0 if not found.
	private static function get_applicant_email_field_id( $form ) : int {
		$fid = 0;

		// Allow an override if you ever want to hard-code it.
		$override = apply_filters( 'pcpi_applicant_email_field_id', 0, $form );
		if ( $override ) {
			return absint( $override );
		}

		if ( empty( $form ) || empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return 0;
		}

		foreach ( $form['fields'] as $field ) {
			// Fields are usually GF_Field objects.
			$type = '';
			$id   = 0;

			if ( is_object( $field ) ) {
				$type = isset( $field->type ) ? (string) $field->type : '';
				$id   = isset( $field->id ) ? absint( $field->id ) : 0;
			} elseif ( is_array( $field ) ) {
				$type = isset( $field['type'] ) ? (string) $field['type'] : '';
				$id   = isset( $field['id'] ) ? absint( $field['id'] ) : 0;
			}

			if ( $id && strtolower( $type ) === 'email' ) {
				$fid = $id;
				break;
			}
		}

		return absint( $fid );
	}


	private static function build_status_pill( string $key, string $label ) : string {
		$key = sanitize_key( $key );
		$cls = 'pcpi-status-pill pcpi-status--' . $key;
		return '<span class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</span>';
	}
	
		/**
		 * Status is derived (no DB writes) so this cannot break anything.
		 *
		 * Keys:
		 * - pending   (no questionnaire entry)
		 * - submitted (questionnaire exists but not marked review-ready)
		 * - ready     (review-ready but no review entry yet)
		 * - reviewed  (review entry exists but PDF not yet available)
		 * - pdf       (PDF is available)
		 */
	private static function compute_applicant_status( int $q_entry_id, bool $review_ready, int $review_entry_id, bool $pdf_ready ) : array {
		// Default: Questionnaire not submitted yet.
		if ( $q_entry_id <= 0 ) {
			return [ 'key' => 'pending', 'label' => 'Questionnaire Pending' ];
		}

		// If a PDF is already available (kiosk workflows or otherwise), show that immediately.
		// This preserves the behavior where the dashboard reflects "PDF Ready" even when
		// no review entry exists.
		if ( $pdf_ready ) {
			return [ 'key' => 'pdf', 'label' => 'PDF Ready' ];
		}
	
		// Questionnaire submitted, but not yet marked ready for review.
		if ( ! $review_ready ) {
			return [ 'key' => 'submitted', 'label' => 'Submitted' ];
		}
	
		// Ready for staff review, but staff/examiner review entry not created yet.
		if ( $review_entry_id <= 0 ) {
			return [ 'key' => 'ready', 'label' => 'Ready for Review' ];
		}
	
		// Review exists (PDF not ready yet).
		return [ 'key' => 'reviewed', 'label' => 'Review Complete' ];
	}


	/**
	 * Try to detect hidden field IDs on a form by inputName and/or label/adminLabel patterns.
	 * This is a safety net when config/registry does not specify relationship field IDs yet.
	 *
	 * @param int      $form_id
	 * @param string[] $input_names
	 * @param string[] $label_patterns Case-insensitive substrings to match against label/adminLabel.
	 * @return string[] Field IDs as strings.
	 */
	private static function detect_hidden_field_ids( int $form_id, array $input_names = [], array $label_patterns = [] ) : array {
		if ( ! class_exists( 'GFAPI' ) ) {
			return [];
		}

		$form = GFAPI::get_form( $form_id );
		if ( empty( $form ) || is_wp_error( $form ) || empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return [];
		}

		$ids = [];

		foreach ( $form['fields'] as $field ) {
			if ( empty( $field ) || ! is_object( $field ) ) {
				continue;
			}

			// Only consider hidden fields (relationship anchors are hidden in this project).
			$type = method_exists( $field, 'get_input_type' ) ? (string) $field->get_input_type() : (string) ( $field->type ?? '' );
			if ( strtolower( $type ) !== 'hidden' ) {
				continue;
			}

			$field_id = isset( $field->id ) ? (string) $field->id : '';
			if ( $field_id === '' ) {
				continue;
			}

			$input_name = '';
			if ( isset( $field->inputName ) ) {
				$input_name = (string) $field->inputName;
			}

			$label = '';
			if ( isset( $field->label ) ) {
				$label .= (string) $field->label;
			}
			if ( isset( $field->adminLabel ) && (string) $field->adminLabel !== '' ) {
				$label .= ' ' . (string) $field->adminLabel;
			}

			$matched = false;

			if ( ! empty( $input_names ) && $input_name !== '' ) {
				foreach ( $input_names as $n ) {
					if ( $n !== '' && strtolower( $input_name ) === strtolower( $n ) ) {
						$matched = true;
						break;
					}
				}
			}

			if ( ! $matched && ! empty( $label_patterns ) && $label !== '' ) {
				$hay = strtolower( $label );
				foreach ( $label_patterns as $p ) {
					$p = strtolower( (string) $p );
					if ( $p !== '' && strpos( $hay, $p ) !== false ) {
						$matched = true;
						break;
					}
				}
			}

			if ( $matched ) {
				$ids[] = $field_id;
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
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
		// Use configured parent form id (Applicant) as the canonical dashboard list source.
		// This prevents accidental listing of the questionnaire form when shortcodes still say form_id="1".
		$cfg = pcpi_polygraph_gf_config();
		if ( ! empty( $cfg['PARENT_FORM_ID'] ) ) {
			$form_id = absint( $cfg['PARENT_FORM_ID'] );
		}

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

		$q_parent_field_ids = self::get_questionnaire_parent_field_ids( $QUESTIONNAIRE_FORM_ID, $cfg );
		if ( empty( $q_parent_field_ids ) && $Q_PARENT_APPLICANT_EID_FID !== '' ) { $q_parent_field_ids = [ $Q_PARENT_APPLICANT_EID_FID ]; }
		$REVIEW_PARENT_Q_EID_FID    = (string) $cfg['REVIEW_PARENT_Q_EID_FID'];
		$REVIEW_PARENT_A_EID_FID    = (string) $cfg['REVIEW_PARENT_A_EID_FID'];

		$AGENCY_NAME_FID  = (string) $cfg['AGENCY_NAME_FID'];
		$AGENCY_EMAIL_FID = (string) $cfg['AGENCY_EMAIL_FID'];

		// Accept multiple possible parent-link field ids (auto-detected + legacy fallbacks)
		$q_parent_field_ids = array_values( array_unique( array_filter( array_merge( $q_parent_field_ids, [ '578', '579' ] ) ) ) );
		$review_parent_q_field_ids = array_values( array_unique( array_filter( [ $REVIEW_PARENT_Q_EID_FID, '607', '643' ] ) ) );
		
		//$questionnaire_parent_field_ids = array_values( array_unique( array_filter( (array) ( $workflow['QUESTIONNAIRE_PARENT_APPLICANT_EID_FID'] ?? [] ) ) ) );
		//$review_parent_q_field_ids = array_values( array_unique( array_filter( (array) ( $workflow['REVIEW_PARENT_Q_EID_FID'] ?? [] ) ) ) );


		if ( ! self::gf_is_available() ) {
			return '<p style="color:red;">Gravity Forms is not active.</p>';
		}


		$search_criteria = [ 'status' => 'active' ];
		$sorting         = [ 'key' => 'date_created', 'direction' => 'DESC' ];

		// Determine which form(s) represent "root" entries for the Staff Dashboard.
		// - Applicant-root workflows: applicant_form_id
		// - Questionnaire-root workflows (kiosk/iPad): source_form_id
		$root_form_ids = [];
		if ( class_exists( 'PCPI_Workflow_Engine' ) && method_exists( 'PCPI_Workflow_Engine', 'get_workflows' ) ) {
			$workflows = (array) PCPI_Workflow_Engine::get_workflows();
			foreach ( $workflows as $wf ) {
				$wf = (array) $wf;
				$rid = ! empty( $wf['applicant_form_id'] ) ? absint( $wf['applicant_form_id'] ) : absint( $wf['source_form_id'] ?? 0 );
				if ( $rid > 0 ) {
					$root_form_ids[] = $rid;
				}
			}
		}
		// Fallback to legacy single-form behavior.
		if ( empty( $root_form_ids ) ) {
			$root_form_ids = [ $form_id ];
		}

		$root_form_ids = array_values( array_unique( array_filter( array_map( 'absint', $root_form_ids ) ) ) );

		// Fetch entries across all root forms, then sort + paginate in PHP.
		$all_entries = [];
		foreach ( $root_form_ids as $rid ) {
			$res = self::fetch_entries_paged( $rid, $search_criteria, $sorting, 200 );
			if ( is_wp_error( $res ) ) {
				return '<p>Error fetching entries: ' . esc_html( $res->get_error_message() ) . '</p>';
			}
			if ( ! empty( $res ) ) {
				$all_entries = array_merge( $all_entries, $res );
			}
		}

		if ( empty( $all_entries ) ) {
			return '<p>All caught up!<br>No pending applicant comments to review.</p>';
		}

		usort( $all_entries, function ( $a, $b ) {
			$da = isset( $a['date_created'] ) ? strtotime( (string) $a['date_created'] ) : 0;
			$db = isset( $b['date_created'] ) ? strtotime( (string) $b['date_created'] ) : 0;
			return $db <=> $da;
		} );

		$total_entries = count( $all_entries );
		$total_pages   = ( $total_entries > 0 ) ? (int) ceil( $total_entries / $per_page ) : 1;
		$page          = min( max( 1, $page ), max( 1, $total_pages ) );

		$offset  = ( $page - 1 ) * $per_page;
		$entries = array_slice( $all_entries, $offset, $per_page );
		static $cache_review_ready        = [];
		static $cache_q_entry_id          = [];
		static $cache_pdf_by_qeid         = [];
		static $cache_review_entry_by_qid = [];

		$html = '<div class="pcpi-staff-dashboard"><table class="pcpi-gf-table">
		<colgroup>
  			<col class="pcpi-col-applicant">
  			<col class="pcpi-col-submitted">
  			<col class="pcpi-col-status">
  			<col class="pcpi-col-actions">
		</colgroup>
	<thead>
		<tr>
			<th>Applicant</th>
			<th>Submitted</th>
			<th class="pcpi-status-col">Status</th>
<th class="pcpi-gf-actions-col">
	<div class="pcpi-actions-header">
		<span class="pcpi-actions-title">Actions</span>

		<div class="pcpi-staff-help">
			<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
			<a href="' . self::get_staff_help_url() . '" class="pcpi-staff-help-link" target="_blank" rel="noopener noreferrer">
				Help
			</a>
		</div>
	</div>
</th>

		</tr>
	</thead>
	<tbody>';

		// Cache per-form Applicant email field id for Resend modal prefill (supports multiple root forms).
		$email_fid_cache = [];

		$i = 0;

		foreach ( $entries as $e ) {

			$entry_id      = absint( rgar( $e, 'id' ) );
			$entry_form_id = absint( rgar( $e, 'form_id' ) ); // root form id for this row

			list( $first_raw, $last_raw ) = self::get_entry_first_last( $entry_form_id, $e, (string) $a['first_name_field'], (string) $a['last_name_field'] );
			$last  = $last_raw !== '' ? $last_raw : '—';
			$first = $first_raw !== '' ? $first_raw : '—';

			$applicant_display = trim( $last . ', ' . $first );

						// Workflow label (from Workflow Engine registry) shown under applicant name.
			$workflow_key   = self::resolve_workflow_key_from_entry( $e );
			$workflow_label = '';

			if ( class_exists( 'PCPI_Workflow_Engine' ) && method_exists( 'PCPI_Workflow_Engine', 'get_workflow_label' ) ) {
				$workflow_label = (string) PCPI_Workflow_Engine::get_workflow_label( $workflow_key );
			} else {
				$workflow_label = $workflow_key;
			}

$applicant_display_html = esc_html( $applicant_display );
			if ( $workflow_label !== '' ) {
				$applicant_display_html .= '<br><span class="pcpi-workflow-label">' . esc_html( $workflow_label ) . '</span>';
			}


			$applicant_name    = trim( $first . ' ' . $last );

			// NEW: Best-effort Applicant email for Resend modal prefill.
			$applicant_email = '';
			if ( $entry_form_id > 0 ) {
				if ( ! array_key_exists( $entry_form_id, $email_fid_cache ) ) {
					$form_obj = GFAPI::get_form( $entry_form_id );
					$email_fid_cache[ $entry_form_id ] = self::get_applicant_email_field_id( $form_obj );
				}
				$email_fid = $email_fid_cache[ $entry_form_id ];
				if ( ! empty( $email_fid ) ) {
					$applicant_email = trim( (string) rgar( $e, (string) $email_fid ) );
				}
			}

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

			// Per-row workflow resolution (single source of truth).
			$workflow_key = self::resolve_workflow_key_from_entry( $e, $cfg );
			$workflow     = [];
			if ( class_exists( 'PCPI_Workflow_Engine' ) && method_exists( 'PCPI_Workflow_Engine', 'get_workflow' ) ) {
				$workflow = (array) PCPI_Workflow_Engine::get_workflow( $workflow_key );
			}

			// Workflow-specific IDs (fallback to legacy config values when missing).
			$row_questionnaire_form_id = absint( $workflow['source_form_id'] ?? ( $workflow['questionnaire_form_id'] ?? $QUESTIONNAIRE_FORM_ID ) );
			$row_pdf_id                = (string) ( $workflow['pdf_id'] ?? $PDF_ID );

			$row_review_form_ids = [];
			if ( ! empty( $workflow['review_form_ids'] ) && is_array( $workflow['review_form_ids'] ) ) {
				$row_review_form_ids = array_values( array_unique( array_map( 'absint', $workflow['review_form_ids'] ) ) );
			} else {
				$row_review_form_ids = $REVIEW_FORM_IDS;
			}

			$row_q_parent_field_ids = [];
			$row_q_parent_fid       = absint( $workflow['questionnaire_parent_applicant_field_id'] ?? 0 );
			if ( $row_q_parent_fid > 0 ) {
				$row_q_parent_field_ids = [ (string) $row_q_parent_fid ];
			} else {
				$row_q_parent_field_ids = self::get_questionnaire_parent_field_ids( $row_questionnaire_form_id, $cfg );
				if ( empty( $row_q_parent_field_ids ) && $Q_PARENT_APPLICANT_EID_FID !== '' ) {
					$row_q_parent_field_ids = [ $Q_PARENT_APPLICANT_EID_FID ];
				}
			}

			$row_review_parent_q_field_ids = [];
			$row_review_parent_q_fid       = absint( $workflow['review_parent_questionnaire_field_id'] ?? 0 );
			if ( $row_review_parent_q_fid > 0 ) {
				$row_review_parent_q_field_ids = [ (string) $row_review_parent_q_fid ];
			} else {
				$row_review_parent_q_field_ids = $review_parent_q_field_ids;
			}

			$row_review_parent_a_fid = absint( $workflow['review_parent_applicant_field_id'] ?? 0 );

			// Questionnaire-root workflow: the current row entry IS the questionnaire.
			if ( empty( $workflow['applicant_form_id'] ) ) {
				$review_ready = true;
				$q_entry_id   = $entry_id;
				$cache_review_ready[ $entry_id ] = $review_ready;
				$cache_q_entry_id[ $entry_id ]   = $q_entry_id;
			} elseif ( array_key_exists( $entry_id, $cache_review_ready ) ) {
				$review_ready = (bool) $cache_review_ready[ $entry_id ];
				$q_entry_id   = isset( $cache_q_entry_id[ $entry_id ] ) ? (int) $cache_q_entry_id[ $entry_id ] : 0;
			} else {
				$q_entries = [];

				foreach ( $row_q_parent_field_ids as $fid ) {
					$tmp = GFAPI::get_entries(
						$row_questionnaire_form_id,
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
					$review_ready = ( $Q_REVIEW_DONE_FLAG_FID === '' ) ? true : ( rgar( $q_entries[0], $Q_REVIEW_DONE_FLAG_FID ) === '1' );
				}

				$cache_review_ready[ $entry_id ] = $review_ready;
				$cache_q_entry_id[ $entry_id ]   = $q_entry_id;
			}

			// -----------------------------------------------------------------
			// Kiosk workflows:
			// - No examiner review required.
			// - Disable Review + Send PDF actions (but keep them visible).
			// - Summary becomes available as soon as the questionnaire is submitted.
			// -----------------------------------------------------------------
			$is_kiosk = false;
			if ( isset( $workflow['entry_mode'] ) && (string) $workflow['entry_mode'] === 'kiosk' ) {
				$is_kiosk = true;
			} elseif ( ! empty( $workflow['kiosk'] ) || ! empty( $workflow['public_kiosk'] ) ) {
				$is_kiosk = true;
			} elseif ( empty( $workflow['applicant_form_id'] ) ) {
				// Questionnaire-root workflows are effectively kiosk/public-start flows.
				$is_kiosk = true;
			}

			/* REVIEW BUTTON */

if ( $is_kiosk ) {
	$review_btn = '<span class="gf-action-btn pcpi-btn-review pcpi-btn-disabled gf-disabled disabled" aria-disabled="true" title="Review is not used for kiosk workflows">Review</span>';

} elseif ( $q_entry_id > 0 ) {

	$review_url   = self::build_review_url( $workflow, $workflow_key, (int) rgar( $e, 'id' ), $q_entry_id );

	$review_btn = '<a href="' . esc_url( $review_url ) . '" target="_blank" rel="noopener noreferrer" class="gf-action-btn pcpi-btn-review">Review</a>';

} else {
					$title = 'Questionnaire not submitted yet';
				if ( $debug ) {
					$title .= ' (searched form ' . $row_questionnaire_form_id . ' fields ' . implode( ',', $row_q_parent_field_ids ) . ' for value ' . $entry_id . ')';
				}
				// Disabled: render as a non-link but keep tooltip/title.
				// Add gf-disabled + pcpi-btn-disabled so it looks grey like other disabled actions.
				$review_btn = '<span class="gf-action-btn pcpi-btn-review pcpi-btn-disabled gf-disabled disabled" aria-disabled="true" title="' . esc_attr( $title ) . '">Review</span>';
}

/* SUMMARY (PDF) + determine Review Entry ID
 *
 * We treat the PDF as "ready" once a Review Entry exists.
 * For Summary we prefer a signed, expiring link (pcpi_sd_build_secure_pdf_link)
 * provided by the Workflow Engine. If it isn't available, we fall back to
 * the Gravity PDF public URL (/pdf/{pdf_id}/{entry_id}/).
 */
			$pdf_ready       = false;
			$pdf_url         = '';
			$review_entry_id = 0;

			// Kiosk summary: build PDF directly from the questionnaire entry (no review entry required).
			if ( $is_kiosk ) {
				if ( $q_entry_id > 0 && $row_pdf_id ) {
					if ( function_exists( 'pcpi_build_secure_pdf_link' ) ) {
						$pdf_url = (string) pcpi_build_secure_pdf_link( $q_entry_id, (string) $row_pdf_id );
					} else {
						$pdf_url = site_url(
							sprintf(
								'/pdf/%s/%d/',
								rawurlencode( (string) $row_pdf_id ),
								(int) $q_entry_id
							)
						);
					}
					$pdf_ready = ( $pdf_url !== '' );
				}

			} elseif ( $q_entry_id > 0 && array_key_exists( $q_entry_id, $cache_pdf_by_qeid ) ) {
				$pdf_url         = (string) $cache_pdf_by_qeid[ $q_entry_id ];
				$pdf_ready       = ( $pdf_url !== '' );
				$review_entry_id = isset( $cache_review_entry_by_qid[ $q_entry_id ] ) ? absint( $cache_review_entry_by_qid[ $q_entry_id ] ) : 0;
			} else {

				if ( $q_entry_id > 0 ) {

					$review_entries = [];

					foreach ( $row_review_form_ids as $rid ) {
						foreach ( $row_review_parent_q_field_ids as $rfid ) {
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

					if ( empty( $review_entries ) && $row_review_parent_a_fid ) {
						foreach ( $row_review_form_ids as $rid ) {
							$tmp_reviews = GFAPI::get_entries(
								$rid,
								[
									'status'        => 'active',
									'field_filters' => [
										[ 'key' => (string) $row_review_parent_a_fid, 'value' => (string) $entry_id ],
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


					// NEW (v2, parallel fallback): Use Workflow Engine registry to locate the Review entry.
					// This avoids brittle hard-coded REVIEW_FORM_IDS/field IDs when workflows scale.
					if ( empty( $review_entries ) && $q_entry_id > 0 && class_exists( 'PCPI_Workflow_Engine' ) && method_exists( 'PCPI_Workflow_Engine', 'get_workflows' ) ) {
						try {
							
							$wf_key = $workflow_key ?: self::resolve_workflow_key_from_entry( $e, $cfg );
							$wfs    = (array) PCPI_Workflow_Engine::get_workflows();
							$wf     = ( $wf_key && isset( $wfs[ $wf_key ] ) ) ? (array) $wfs[ $wf_key ] : [];
							$rid    = (int) ( $wf['review_form_id'] ?? 0 );
							$rfid   = (int) ( $wf['review_parent_questionnaire_field_id'] ?? 0 );
							if ( $rid && $rfid ) {
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
								}
							}
						} catch ( \Throwable $t ) {
							// Silent: fallback should never break the dashboard.
						}
					}
					if ( ! empty( $review_entries ) ) {
						$review_entry_id = absint( rgar( $review_entries[0], 'id' ) );

					// Prefer a signed, expiring PDF link (secure-links.php).
					if ( function_exists( 'pcpi_build_secure_pdf_link' ) ) {
						$pdf_url = (string) pcpi_build_secure_pdf_link( $review_entry_id, (string) $row_pdf_id );
					} elseif ( $row_pdf_id ) {
						// Fallback: Gravity PDF public URL (less secure).
						$pdf_url = site_url(
							sprintf(
								'/pdf/%s/%d/',
								rawurlencode( (string) $row_pdf_id ),
								$review_entry_id
							)
						);
					}

					$pdf_ready = ( $pdf_url !== '' );
					}
				}

				$cache_pdf_by_qeid[ $q_entry_id ]         = $pdf_ready ? $pdf_url : '';
				$cache_review_entry_by_qid[ $q_entry_id ] = $review_entry_id;
			}

			if ( $pdf_ready && $pdf_url !== '' ) {
				$summary_btn = '<a href="' . esc_url( $pdf_url ) . '" target="_blank" rel="noopener noreferrer" class="gf-action-btn pcpi-btn-summary">Summary</a>';
			} else {
				$summary_btn = $is_kiosk
					? '<span class="gf-action-btn gf-disabled pcpi-btn-disabled" title="Summary will be available after the form is submitted">Summary</span>'
					: '<span class="gf-action-btn gf-disabled pcpi-btn-disabled" title="PDF will be available after review is submitted">Summary</span>';
			}

			/* SEND PDF BUTTON (opens modal, uses signed link email) */
			if ( $is_kiosk ) {
				$sendpdf_btn = '<span class="gf-action-btn gf-disabled pcpi-btn-disabled" title="Send PDF is not used for kiosk workflows">Send PDF</span>';
			} elseif ( $pdf_ready && $review_entry_id > 0 ) {
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

			// STATUS (derived)
			$status_meta = self::compute_applicant_status( $q_entry_id, $review_ready, $review_entry_id, $pdf_ready );
			$status_html = self::build_status_pill(
				(string) ( $status_meta['key'] ?? 'pending' ),
				(string) ( $status_meta['label'] ?? 'Questionnaire Pending' )
			);

			// Kiosk workflows do not use Resend (entry starts on device).
			$resend_btn = '';
			if ( $is_kiosk ) {
				$resend_btn = '<span class="gf-action-btn pcpi-btn-resend pcpi-btn-disabled gf-disabled disabled" aria-disabled="true" title="Resend is not used for kiosk workflows">Resend</span>';
			} else {
				$resend_btn = '<button type="button" class="gf-action-btn pcpi-btn-resend pcpi-gf-resend-entry" data-id="' . esc_attr( $entry_id ) . '" data-applicant-email="' . esc_attr( $applicant_email ) . '" data-applicant-name="' . esc_attr( $applicant_name ) . '">Resend</button>';
			}

			$actions = '
				<div class="pcpi-actions">
					' . $review_btn . '
					' . $summary_btn . '
					' . $sendpdf_btn . '
					' . $resend_btn . '
					<button type="button" class="gf-action-btn pcpi-btn-delete pcpi-gf-delete-entry" data-id="' . esc_attr( $entry_id ) . '">Delete</button>
				</div>
				' . $debug_html;

			$bg_class = ( $i++ % 2 ) ? 'pcpi-row-odd' : 'pcpi-row-even';

			$html .= '<tr class="' . esc_attr( $bg_class ) . '">
				<td data-label="Applicant">' . $applicant_display_html . '</td>
				<td data-label="Submitted">' . esc_html( $date ) . '</td>
				<td class="pcpi-status-td" data-label="Status">' . $status_html . '</td>
				<td class="pcpi-actions-td" data-label="Actions">' . $actions . '</td>
			</tr>';
		}

		$html .= '</tbody></table>';

		if ( $total_entries > 0 ) {
			$html .= self::render_pagination( $page, $total_pages, $page_var );
		}

		// Add Applicant modal (desktop only; mobile falls back to link navigation).
		$html .= self::render_add_applicant_modal( (int) $cfg['PARENT_FORM_ID'] );
		$html .= '</div>';

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

		// Workflow-driven validation (per row).
		$workflow_key = 'polygraph';
		$workflow     = [];
		if ( class_exists( 'PCPI_Workflow_Engine' ) && method_exists( 'PCPI_Workflow_Engine', 'get_workflow' ) ) {
			$workflow_key = self::resolve_workflow_key_from_entry( $app );
			$workflow     = (array) PCPI_Workflow_Engine::get_workflow( $workflow_key );
		}
		$root_form_id = ! empty( $workflow['applicant_form_id'] )
			? absint( $workflow['applicant_form_id'] )
			: absint( $workflow['source_form_id'] ?? $PARENT_FORM_ID );

		// Kiosk workflows do not support Resend.
		$is_kiosk = false;
		if ( isset( $workflow['entry_mode'] ) && (string) $workflow['entry_mode'] === 'kiosk' ) {
			$is_kiosk = true;
		} elseif ( ! empty( $workflow['kiosk'] ) || ! empty( $workflow['public_kiosk'] ) ) {
			$is_kiosk = true;
		} elseif ( empty( $workflow['applicant_form_id'] ) ) {
			$is_kiosk = true;
		}

		if ( $is_kiosk ) {
			wp_send_json_error( [ 'message' => 'Resend is disabled for kiosk workflows.' ] );
		}

		if ( absint( rgar( $app, 'form_id' ) ) !== $root_form_id ) {
			wp_send_json_error( [ 'message' => 'Entry is not a root form entry for this workflow.' ] );
		}

		$agency_name  = trim( (string) rgar( $app, $AGENCY_NAME_FID ) );
		$agency_email = trim( (string) rgar( $app, $AGENCY_EMAIL_FID ) );

		$to_name  = ( $to_name_override !== '' ) ? $to_name_override : $agency_name;
		$to_email = ( $to_email_override !== '' ) ? $to_email_override : $agency_email;
		$to_email = trim( (string) $to_email );

		if ( $to_email === '' || ! is_email( $to_email ) ) {
			wp_send_json_error( [ 'message' => 'Agency email is missing or invalid.' ] );
		}

				// Resolve workflow-specific PDF ID (fallback to legacy config).
		$pdf_id = (string) ( $workflow['pdf_id'] ?? ( $cfg['PDF_ID'] ?? '' ) );

// Build signed PDF download link
		$link = function_exists( 'pcpi_build_secure_pdf_link' )
			? pcpi_build_secure_pdf_link( $review_entry_id, (string) $pdf_id )
		: '';
		
		if ( $link === '' ) {
			wp_send_json_error( [ 'message' => 'Could not generate secure PDF link.' ] );
		}

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
$wf_label = ( class_exists( 'PCPI_Workflow_Engine' ) && method_exists( 'PCPI_Workflow_Engine', 'get_workflow_label' ) ) ? PCPI_Workflow_Engine::get_workflow_label( $workflow_key ) : 'Polygraph Questionnaire';

$subject = $wf_label . ( $applicant_name ? ' – ' . $applicant_name : '' );

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

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		if ( ! $entry_id ) {
			wp_send_json_error( [ 'message' => 'Missing entry_id.' ] );
		}

		// Fallback to legacy local implementation only if Workflow Engine is unavailable.
		$cfg = pcpi_polygraph_gf_config();
		$PARENT_FORM_ID = absint( $cfg['PARENT_FORM_ID'] );

		// $entry_id is already validated above.
		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || empty( $entry ) ) {
			wp_send_json_error( [ 'message' => 'Applicant entry not found.' ] );
		}

		$form_id = absint( rgar( $entry, 'form_id' ) );

		$workflow_key = self::resolve_workflow_key_from_entry( $entry );
		$workflow     = [];
		if ( class_exists( 'PCPI_Workflow_Engine' ) && method_exists( 'PCPI_Workflow_Engine', 'get_workflow' ) ) {
			$workflow = (array) PCPI_Workflow_Engine::get_workflow( $workflow_key );
		}
		$root_form_id = ! empty( $workflow['applicant_form_id'] )
			? absint( $workflow['applicant_form_id'] )
			: absint( $workflow['source_form_id'] ?? $PARENT_FORM_ID );

		if ( $form_id !== $root_form_id ) {
			wp_send_json_error( [ 'message' => 'Resend must be called with a root entry for this workflow.' ] );
		}

		$form = GFAPI::get_form( $form_id );
		if ( empty( $form ) || is_wp_error( $form ) ) {
			wp_send_json_error( [ 'message' => 'Could not load form.' ] );
		}

		$target_name      = isset( $cfg['RESEND_NOTIFICATION_NAME'] ) ? (string) $cfg['RESEND_NOTIFICATION_NAME'] : 'Questionnaire link';
		$target_name_norm = self::normalize_name( $target_name );

		$sent = false;

		// NEW: Optional override email (used when staff corrects a typo from the dashboard).
		$override_email_raw = isset( $_POST['override_email'] ) ? (string) wp_unslash( $_POST['override_email'] ) : '';
		$override_email     = $override_email_raw !== '' ? sanitize_email( $override_email_raw ) : '';
		if ( $override_email !== '' && ! is_email( $override_email ) ) {
			wp_send_json_error( [ 'message' => 'Please enter a valid email address.' ] );
		}

		// NEW: If an override is provided, force ONLY the target notification to go to that address.
		$notification_filter = null;
		if ( $override_email !== '' ) {
			$notification_filter = function ( $notification, $form, $entry ) use ( $entry_id, $target_name_norm, $override_email ) {
				$n_name = isset( $notification['name'] ) ? (string) $notification['name'] : '';
				if ( $n_name === '' || self::normalize_name( $n_name ) !== $target_name_norm ) {
					return $notification;
				}

				$eid = absint( rgar( $entry, 'id' ) );
				if ( $eid !== absint( $entry_id ) ) {
					return $notification;
				}

				$notification['to'] = $override_email;
				return $notification;
			};

			add_filter( 'gform_notification', $notification_filter, 10, 3 );
		}

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

		if ( $notification_filter ) {
			remove_filter( 'gform_notification', $notification_filter, 10 );
		}

		if ( ! $sent ) {
			wp_send_json_error( [ 'message' => 'Notification did not send.' ] );
		}

		$msg = ( $override_email !== '' ) ? ( 'Sent to ' . $override_email . '.' ) : 'Sent.';
		wp_send_json_success( [ 'message' => $msg ] );
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

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		if ( ! $entry_id ) {
			wp_send_json_error( [ 'message' => 'Missing entry_id.' ] );
		}

		// Canonical cascade delete lives in the Workflow Engine.
		if ( class_exists( 'PCPI_Workflow_Engine' ) && method_exists( 'PCPI_Workflow_Engine', 'cascade_delete_applicant' ) ) {
			$result = PCPI_Workflow_Engine::cascade_delete_applicant( $entry_id );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( [ 'message' => (string) $result->get_error_message() ] );
			}

			wp_send_json_success(
				[
					'deleted_parent'        => absint( $result['deleted_parent'] ?? 0 ),
					'deleted_questionnaire' => absint( $result['deleted_questionnaire'] ?? 0 ),
					'deleted_review'        => absint( $result['deleted_review'] ?? 0 ),
					'deleted_nested'        => absint( $result['deleted_nested'] ?? 0 ),
				]
			);
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

		// Parent-link field ids (config + auto-detect + legacy fallbacks)
		$q_parent_field_ids        = array_values( array_unique( array_filter( [ $Q_PARENT_APPLICANT_EID_FID, '578', '579' ] ) ) );
		$review_parent_q_field_ids = array_values( array_unique( array_filter( [ $REVIEW_PARENT_Q_EID_FID, '607', '643' ] ) ) );

		// Auto-detect relationship fields from form meta as a safety net.
		$detected_q = self::detect_hidden_field_ids(
			$QUESTIONNAIRE_FORM_ID,
			[ 'parent_applicant_entry_id', 'parent_applicant_entry', 'parent_applicant_eid' ],
			[ 'parent applicant', 'parent applicant entry' ]
		);
		if ( ! empty( $detected_q ) ) {
			$q_parent_field_ids = array_values( array_unique( array_merge( $q_parent_field_ids, $detected_q ) ) );
		}

		$detected_review_q = [];
		$detected_review_a = [];
		foreach ( $row_review_form_ids as $rid ) {
			$detected_review_q = array_merge(
				$detected_review_q,
				self::detect_hidden_field_ids( $rid, [ 'parent_questionnaire_entry_id', 'parent_questionnaire_entry', 'parent_questionnaire_eid' ], [ 'parent questionnaire', 'parent questionnaire entry' ] )
			);
			$detected_review_a = array_merge(
				$detected_review_a,
				self::detect_hidden_field_ids( $rid, [ 'parent_applicant_entry_id', 'parent_applicant_entry', 'parent_applicant_eid' ], [ 'parent applicant', 'parent applicant entry' ] )
			);
		}
		$detected_review_q = array_values( array_unique( array_filter( array_map( 'strval', $detected_review_q ) ) ) );
		$detected_review_a = array_values( array_unique( array_filter( array_map( 'strval', $detected_review_a ) ) ) );
		if ( ! empty( $detected_review_q ) ) {
			$review_parent_q_field_ids = array_values( array_unique( array_merge( $review_parent_q_field_ids, $detected_review_q ) ) );
		}
		if ( (string) $REVIEW_PARENT_A_EID_FID === '' && ! empty( $detected_review_a ) ) {
			$REVIEW_PARENT_A_EID_FID = (string) $detected_review_a[0];
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
		foreach ( $row_q_parent_field_ids as $fid ) {
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
						foreach ( $row_q_parent_field_ids as $fid ) {
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

			foreach ( $row_review_form_ids as $rid ) {
				foreach ( $row_review_parent_q_field_ids as $rfid ) {

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

			if ( empty( $review_entries ) && $row_review_parent_a_fid ) {
				foreach ( $row_review_form_ids as $rid ) {
					$tmp_reviews = self::fetch_entries_paged(
						$rid,
						[
							'status'        => 'active',
							'field_filters' => [
								[ 'key' => (string) $row_review_parent_a_fid, 'value' => (string) $entry_id ],
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

			foreach ( $row_review_form_ids as $rid ) {

				$tmp_reviews = self::fetch_entries_paged(
					$rid,
					[
						'status'        => 'active',
						'field_filters' => [
							[ 'key' => (string) $row_review_parent_a_fid, 'value' => (string) $entry_id ],
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

	/**
	 * Render the Add Applicant modal container.
	 * Note: On mobile, the Add Applicant button should navigate normally (JS does not intercept).
	 */
	private static function render_add_applicant_modal( int $form_id ) : string {

		$form_id = absint( $form_id );
		if ( $form_id <= 0 ) {
			return '';
		}

		// Build the modal HTML. The GF shortcode output is included so desktop can open it instantly.
		$gf = do_shortcode(
			sprintf(
				'[gravityforms id="%d" title="false" description="false" ajax="true"]',
				$form_id
			)
		);

		ob_start();
		?>
		<div id="pcpi-add-applicant-modal" class="pcpi-modal pcpi-modal--add" role="dialog" aria-modal="true" aria-hidden="true" style="display:none;">
			<div class="pcpi-modal__backdrop" data-pcpi-add-close="1"></div>
			<div class="pcpi-modal__panel" role="document">
				<div class="pcpi-modal__header pcpi-modal__header--with-x">
					<h3 class="pcpi-modal__title">Add Applicant</h3>
					<button type="button" class="pcpi-modal__x" aria-label="Close" data-pcpi-add-close="1">×</button>
				</div>
				<div class="pcpi-modal__body">
					<?php echo $gf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}


}