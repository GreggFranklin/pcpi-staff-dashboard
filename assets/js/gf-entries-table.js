/* global jQuery, PCPI_GF */
(function ($) {
	"use strict";

	// Prevent double-binding if script is loaded twice.
	window.PCPI_GF = window.PCPI_GF || {};
	if (window.PCPI_GF.__entriesTableBound) return;
	window.PCPI_GF.__entriesTableBound = true;

	function postAjax(payload) {
		return $.post(PCPI_GF.ajaxUrl, payload);
	}

	function getAjaxErrorMessage(xhr) {
		try {
			const json = xhr && xhr.responseJSON;
			if (json && json.data && json.data.message) return json.data.message;
			if (json && json.message) return json.message;
		} catch (e) {}
		return "Request failed.";
	}

	

	// ------------------------------------------------------------------
	// ADD APPLICANT MODAL (Gutenberg button trigger)
	// ------------------------------------------------------------------
	const ADD_MODAL_SELECTOR = "#pcpi-add-applicant-modal";
	const ADD_BTN_SELECTOR = (PCPI_GF && PCPI_GF.addApplicantBtnSelector) ? PCPI_GF.addApplicantBtnSelector : ".pcpi-add-applicant-btn";

	let pcpiLastAddTrigger = null;
	let pcpiAddFocusHandlerBound = false;

	function getFocusable($root) {
		return $root
			.find("a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex='-1'])")
			.filter(":visible");
	}

	function openAddApplicantModal(triggerEl) {
		const $modal = $(ADD_MODAL_SELECTOR);
		if (!$modal.length) {
			window.alert("Add Applicant form is not available on this page.");
			return;
		}

		pcpiLastAddTrigger = triggerEl || null;

		$modal.attr("aria-hidden", "false").show();

		// Focus first input in the form (best effort)
		setTimeout(() => {
			const $first = getFocusable($modal).first();
			if ($first.length) $first.trigger("focus");
		}, 0);

		// Bind focus trap once
		if (!pcpiAddFocusHandlerBound) {
			pcpiAddFocusHandlerBound = true;

			$(document).on("keydown", function (e) {
				const $m = $(ADD_MODAL_SELECTOR);
				if (!$m.length || $m.attr("aria-hidden") !== "false") return;

				// Escape closes
				if (e.key === "Escape") {
					closeAddApplicantModal();
					return;
				}

				// Trap Tab
				if (e.key === "Tab") {
					const $focusables = getFocusable($m);
					if (!$focusables.length) return;

					const first = $focusables.get(0);
					const last = $focusables.get($focusables.length - 1);
					const active = document.activeElement;

					if (e.shiftKey) {
						if (active === first || active === $m.get(0)) {
							e.preventDefault();
							$(last).trigger("focus");
						}
					} else {
						if (active === last) {
							e.preventDefault();
							$(first).trigger("focus");
						}
					}
				}
			});
		}
	}

	function closeAddApplicantModal() {
		const $modal = $(ADD_MODAL_SELECTOR);
		if (!$modal.length) return;

		$modal.attr("aria-hidden", "true").hide();

		// Restore focus to trigger button (best effort)
		if (pcpiLastAddTrigger) {
			try {
				$(pcpiLastAddTrigger).trigger("focus");
			} catch (e) {}
		}
		pcpiLastAddTrigger = null;
	}

	// Open
	$(document).on("click", ADD_BTN_SELECTOR, function (e) {
		// Mobile: do NOT use a modal. Let the link navigate normally.
		const disableMobile = (PCPI_GF && typeof PCPI_GF.addApplicantDisableMobile !== "undefined") ? !!PCPI_GF.addApplicantDisableMobile : true;
		const mobileMax = (PCPI_GF && PCPI_GF.addApplicantMobileMaxWidth) ? parseInt(PCPI_GF.addApplicantMobileMaxWidth, 10) : 782;

		if (disableMobile && window.matchMedia && window.matchMedia(`(max-width: ${mobileMax}px)`).matches) {
			return; // allow default
		}

		e.preventDefault();
		e.stopPropagation();
		openAddApplicantModal(this);
	});

	// Close (backdrop + cancel)
	$(document).on("click", "[data-pcpi-add-close='1']", function (e) {
		e.preventDefault();
		closeAddApplicantModal();
	});

	// Auto-close + refresh when GF confirmation loads for the add-applicant form
	$(document).on("gform_confirmation_loaded", function (e, formId) {
		const $modal = $(ADD_MODAL_SELECTOR);
		if (!$modal.length || $modal.attr("aria-hidden") !== "false") return;

		const expectedId = parseInt($modal.data("form-id"), 10) || (PCPI_GF && parseInt(PCPI_GF.addApplicantFormId, 10)) || 0;
		if (expectedId && parseInt(formId, 10) === expectedId) {
			// Close
			closeAddApplicantModal();

			// Refresh list (default true)
			const doReload = (PCPI_GF && PCPI_GF.addApplicantAutoReload !== undefined) ? !!PCPI_GF.addApplicantAutoReload : true;
			if (doReload) {
				setTimeout(() => window.location.reload(), 250);
			}
		}
	});

// ------------------------------------------------------------------
	// RESEND (with modal + editable email)
	// ------------------------------------------------------------------
	function buildResendModal() {
		const $modal = $(`
			<div class="pcpi-modal pcpi-modal--resend" role="dialog" aria-modal="true" aria-hidden="true">
				<div class="pcpi-modal__backdrop" data-pcpi-resend-close="1"></div>
				<div class="pcpi-modal__panel" role="document">
					<div class="pcpi-modal__header">
						<h3 class="pcpi-modal__title pcpi-modal__title--resend">Resend Questionnaire Link</h3>
					</div>
					<div class="pcpi-modal__body">
						<label class="pcpi-modal__label" for="pcpi_resend_email">Applicant Email</label>
						<input class="pcpi-modal__input" id="pcpi_resend_email" type="email" value="" />

						<div class="pcpi-modal__note">
							We&apos;ll resend the Questionnaire link to this address.
						</div>

						<label class="pcpi-modal__note" for="pcpi_resend_update" style="display:flex;gap:8px;align-items:flex-start;">
							<input id="pcpi_resend_update" type="checkbox" value="1" />
							<span>Update applicant record with this email</span>
						</label>
					</div>
					<div class="pcpi-modal__footer">
						<button type="button" class="gf-action-btn pcpi-btn-cancel" data-pcpi-resend-close="1">Cancel</button>
						<button type="button" class="gf-action-btn pcpi-btn-send" data-pcpi-resend-send="1">Resend</button>
					</div>
				</div>
			</div>
		`);

		$("body").append($modal);
		return $modal;
	}

	let $resendModal = null;

	function openResendModal(context) {
		if (!$resendModal) $resendModal = buildResendModal();

		$resendModal.data("ctx", context);

		const title = context.applicantName
			? `Resend Questionnaire Link – ${context.applicantName}`
			: "Resend Questionnaire Link";

		$resendModal.find(".pcpi-modal__title--resend").text(title);
		$resendModal.find("#pcpi_resend_email").val(context.applicantEmail || "");

		$resendModal.find("#pcpi_resend_update").prop("checked", false);

		$resendModal.attr("aria-hidden", "false").show();

		setTimeout(() => {
			$resendModal.find("#pcpi_resend_email").trigger("focus");
		}, 0);
	}

	function closeResendModal() {
		if (!$resendModal) return;
		$resendModal.attr("aria-hidden", "true").hide();
	}

	$(document).on("click", "[data-pcpi-resend-close='1']", function (e) {
		e.preventDefault();
		closeResendModal();
	});

	$(document).on("keydown", function (e) {
		if (e.key === "Escape") closeResendModal();
	});

	// Open modal (instead of a simple confirm)
	$(document).on("click", ".pcpi-gf-resend-entry", function (e) {
		e.preventDefault();
		e.stopPropagation();

		const btn = $(this);

		openResendModal({
			entryId: btn.data("id"),
			applicantEmail: btn.data("applicant-email") || "",
			applicantName: btn.data("applicant-name") || "",
		});
	});

	// Resend
	$(document).on("click", "[data-pcpi-resend-send='1']", function (e) {
		e.preventDefault();

		if (!$resendModal) return;
		const ctx = $resendModal.data("ctx") || {};

		const toEmail = ($resendModal.find("#pcpi_resend_email").val() || "").trim();
		if (!toEmail) {
			window.alert("Please enter an email address.");
			return;
		}

		const sendBtn = $(this);
		if (sendBtn.data("pcpiBusy")) return;
		sendBtn.data("pcpiBusy", true);

		postAjax({
			action: "gf_resend_entry",
			security: PCPI_GF.nonceResend,
			entry_id: ctx.entryId,
			override_email: toEmail,
			update_entry: $resendModal.find("#pcpi_resend_update").is(":checked") ? 1 : 0,
		})
			.done(function (resp) {
				if (resp && resp.success) {
					closeResendModal();
					window.alert((resp && resp.data && resp.data.message) || "Sent.");
				} else {
					window.alert((resp && resp.data && resp.data.message) || "Failed.");
				}
			})
			.fail(function (xhr) {
				window.alert(getAjaxErrorMessage(xhr));
			})
			.always(function () {
				sendBtn.data("pcpiBusy", false);
			});
	});


	// ------------------------------------------------------------------
	// DELETE (cascade)
	// ------------------------------------------------------------------
	$(document).on("click", ".pcpi-gf-delete-entry", function (e) {
		e.preventDefault();
		e.stopPropagation();

		const btn = $(this);
		if (btn.data("pcpiBusy")) return;
		btn.data("pcpiBusy", true);

		if (!window.confirm(PCPI_GF.confirmDelete || "Delete?")) {
			btn.data("pcpiBusy", false);
			return;
		}

		postAjax({
			action: "gf_delete_entry",
			security: PCPI_GF.nonceDelete,
			entry_id: btn.data("id"),
		})
			.done(function (resp) {
				if (resp && resp.success) {
					// Remove row
					btn.closest("tr").fadeOut(150, function () {
						$(this).remove();
					});
				} else {
					window.alert((resp && resp.data && resp.data.message) || "Delete failed.");
				}
			})
			.fail(function (xhr) {
				window.alert(getAjaxErrorMessage(xhr));
			})
			.always(function () {
				btn.data("pcpiBusy", false);
			});
	});

	// ------------------------------------------------------------------
	// SEND PDF MODAL
	// - Shows editable Agency Name + Agency Email
	// - Title auto-fills: "Send PDF – <Applicant Name>"
	// ------------------------------------------------------------------
	function buildModal() {
		const $modal = $(`
			<div class="pcpi-modal" role="dialog" aria-modal="true" aria-hidden="true">
				<div class="pcpi-modal__backdrop" data-pcpi-close="1"></div>
				<div class="pcpi-modal__panel" role="document">
					<div class="pcpi-modal__header">
						<h3 class="pcpi-modal__title">Send PDF</h3>
					</div>
					<div class="pcpi-modal__body">
						<label class="pcpi-modal__label" for="pcpi_agency_name">Agency Name</label>
						<input class="pcpi-modal__input" id="pcpi_agency_name" type="text" value="" />

						<label class="pcpi-modal__label" for="pcpi_agency_email">Agency Email</label>
						<input class="pcpi-modal__input" id="pcpi_agency_email" type="email" value="" />

						<div class="pcpi-modal__note">
							A secure, expiring link will be emailed to the agency.
						</div>
					</div>
					<div class="pcpi-modal__footer">
						<button type="button" class="gf-action-btn pcpi-btn-cancel" data-pcpi-close="1">Cancel</button>
						<button type="button" class="gf-action-btn pcpi-btn-send" data-pcpi-send="1">Send</button>
					</div>
				</div>
			</div>
		`);

		$("body").append($modal);
		return $modal;
	}

	let $modal = null;

	function openModal(context) {
		if (!$modal) $modal = buildModal();

		$modal.data("ctx", context);

		const title = context.applicantName
			? `Send PDF – ${context.applicantName}`
			: "Send PDF";

		$modal.find(".pcpi-modal__title").text(title);
		$modal.find("#pcpi_agency_name").val(context.agencyName || "");
		$modal.find("#pcpi_agency_email").val(context.agencyEmail || "");

		$modal.attr("aria-hidden", "false").show();

		// focus email by default
		setTimeout(() => {
			$modal.find("#pcpi_agency_email").trigger("focus");
		}, 0);
	}

	function closeModal() {
		if (!$modal) return;
		$modal.attr("aria-hidden", "true").hide();
	}

	$(document).on("click", "[data-pcpi-close='1']", function (e) {
		e.preventDefault();
		closeModal();
	});

	$(document).on("keydown", function (e) {
		if (e.key === "Escape") closeModal();
	});

	// Open modal
	$(document).on("click", ".pcpi-gf-sendpdf", function (e) {
		e.preventDefault();
		e.stopPropagation();

		const btn = $(this);

		openModal({
			applicantEntryId: btn.data("applicant-id"),
			reviewEntryId: btn.data("review-id"),
			agencyEmail: btn.data("agency-email") || "",
			agencyName: btn.data("agency-name") || "",
			applicantName: btn.data("applicant-name") || "",
		});
	});
	

	// Send
	$(document).on("click", "[data-pcpi-send='1']", function (e) {
		e.preventDefault();

		if (!$modal) return;
		const ctx = $modal.data("ctx") || {};

		const toName = ($modal.find("#pcpi_agency_name").val() || "").trim();
		const toEmail = ($modal.find("#pcpi_agency_email").val() || "").trim();

		if (!toEmail) {
			window.alert("Please enter an email address.");
			return;
		}

		const sendBtn = $(this);
		if (sendBtn.data("pcpiBusy")) return;
		sendBtn.data("pcpiBusy", true);

		postAjax({
			action: "pcpi_send_pdf_link",
			security: PCPI_GF.nonceSendPdf,
			applicant_entry_id: ctx.applicantEntryId,
			review_entry_id: ctx.reviewEntryId,
			to_name: toName,
			to_email: toEmail,
		})
			.done(function (resp) {
				if (resp && resp.success) {
					closeModal();
					window.alert("Sent.");
				} else {
					window.alert((resp && resp.data && resp.data.message) || "Send failed.");
				}
			})
			.fail(function (xhr) {
				window.alert(getAjaxErrorMessage(xhr));
			})
			.always(function () {
				sendBtn.data("pcpiBusy", false);
			});
	});
})(jQuery);