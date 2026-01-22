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
	// RESEND
	// ------------------------------------------------------------------
	$(document).on("click", ".pcpi-gf-resend-entry", function (e) {
		e.preventDefault();
		e.stopPropagation();

		const btn = $(this);
		if (btn.data("pcpiBusy")) return;
		btn.data("pcpiBusy", true);

		if (!window.confirm(PCPI_GF.confirmResend || "Resend?")) {
			btn.data("pcpiBusy", false);
			return;
		}

		postAjax({
			action: "gf_resend_entry",
			security: PCPI_GF.nonceResend,
			entry_id: btn.data("id"),
		})
			.done(function (resp) {
				if (resp && resp.success) {
					window.alert("Sent.");
				} else {
					window.alert((resp && resp.data && resp.data.message) || "Failed.");
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