/* global jQuery, PCPI_GF */
(function ($) {
	"use strict";

	// If this file is loaded twice, do NOT bind twice.
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
		} catch (e) {}
		return "Request failed.";
	}

	// ------------------------------------------------------------
	// RESEND
	// ------------------------------------------------------------
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
			.done(function (res) {
				if (res && res.success) {
					alert((res.data && res.data.message) || "Sent.");
				} else {
					alert((res && res.data && res.data.message) || "Request failed.");
				}
			})
			.fail(function (xhr) {
				alert(getAjaxErrorMessage(xhr));
			})
			.always(function () {
				btn.data("pcpiBusy", false);
			});
	});

	// ------------------------------------------------------------
	// DELETE
	// ------------------------------------------------------------
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
			.done(function (res) {
				if (res && res.success) {
					// Remove the row immediately
					btn.closest("tr").fadeOut(200, function () {
						$(this).remove();
					});
				} else {
					alert((res && res.data && res.data.message) || "Delete failed.");
				}
			})
			.fail(function (xhr) {
				alert(getAjaxErrorMessage(xhr));
			})
			.always(function () {
				btn.data("pcpiBusy", false);
			});
	});

	// ------------------------------------------------------------
	// SEND PDF (signed link)
	// ------------------------------------------------------------
	$(document).on("click", ".pcpi-gf-sendpdf", function (e) {
		e.preventDefault();
		e.stopPropagation();

		const btn = $(this);
		if (btn.data("pcpiBusy")) return;
		btn.data("pcpiBusy", true);

		const applicantId = btn.data("applicant-id");
		const reviewId = btn.data("review-id");
		const defaultEmail = (btn.data("agency-email") || "").toString().trim();

		let toEmail = window.prompt(PCPI_GF.promptSendTo || "Send to which email?", defaultEmail);
		if (toEmail === null) {
			btn.data("pcpiBusy", false);
			return;
		}
		toEmail = toEmail.toString().trim();

		if (!toEmail) {
			alert("Email is required.");
			btn.data("pcpiBusy", false);
			return;
		}

		if (!window.confirm(PCPI_GF.confirmSendPdf || "Send PDF link now?")) {
			btn.data("pcpiBusy", false);
			return;
		}

		postAjax({
			action: "pcpi_send_pdf_link",
			security: PCPI_GF.nonceSendPdf,
			applicant_entry_id: applicantId,
			review_entry_id: reviewId,
			to_email: toEmail,
		})
			.done(function (res) {
				if (res && res.success) {
					alert((res.data && res.data.message) || "Sent.");
				} else {
					alert((res && res.data && res.data.message) || "Send failed.");
				}
			})
			.fail(function (xhr) {
				alert(getAjaxErrorMessage(xhr));
			})
			.always(function () {
				btn.data("pcpiBusy", false);
			});
	});
})(jQuery);