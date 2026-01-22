/* global jQuery, PCPI_GF */
jQuery(function ($) {

	"use strict";

	function postAjax(payload) {
		return $.post(PCPI_GF.ajaxUrl, payload);
	}

	// ------------------------------------------------------------
	// RESEND (bind once, even if script is loaded twice)
	// ------------------------------------------------------------
	$(document)
		.off("click.pcpiResend", ".gf-resend-entry")
		.on("click.pcpiResend", ".gf-resend-entry", function (e) {

			e.preventDefault();
			e.stopPropagation();

			if (!window.confirm(PCPI_GF.confirmResend)) return;

			const btn  = $(this);
			const orig = btn.text();

			// guard against double-click / double-bind
			if (btn.data("pcpiBusy")) return;
			btn.data("pcpiBusy", true);

			btn.prop("disabled", true).text("Sending…");

			postAjax({
				action: "gf_resend_entry",
				entry_id: btn.data("id"),
				security: PCPI_GF.nonceResend
			})
			.done(function (res) {
				btn.prop("disabled", false).text(orig).data("pcpiBusy", false);
				alert(res && res.success ? "Sent!" : "Error sending.");
			})
			.fail(function () {
				btn.prop("disabled", false).text(orig).data("pcpiBusy", false);
				alert("Error sending.");
			});
		});

	// ------------------------------------------------------------
	// DELETE (bind once, even if script is loaded twice)
	// ------------------------------------------------------------
	$(document)
		.off("click.pcpiDelete", ".gf-delete-entry")
		.on("click.pcpiDelete", ".gf-delete-entry", function (e) {

			e.preventDefault();
			e.stopPropagation();

			if (!window.confirm(PCPI_GF.confirmDelete)) return;

			const btn  = $(this);
			const row  = btn.closest("tr");
			const orig = btn.text();

			// guard against double-click / double-bind
			if (btn.data("pcpiBusy")) return;
			btn.data("pcpiBusy", true);

			btn.prop("disabled", true).text("Deleting…");

			postAjax({
				action: "gf_delete_entry",
				entry_id: btn.data("id"),
				security: PCPI_GF.nonceDelete
			})
			.done(function (res) {

				if (res && res.success) {

					const d = res.data || {};
					const parent = parseInt(d.deleted_parent || 0, 10);
					const q      = parseInt(d.deleted_questionnaire || 0, 10);
					const r      = parseInt(d.deleted_review || 0, 10);

					row.fadeOut(250, function () { $(this).remove(); });

					alert(
						"Deleted:\n" +
						"• Applicant: " + parent + "\n" +
						"• Questionnaire: " + q + "\n" +
						"• Review: " + r
					);

				} else {

					btn.prop("disabled", false).text(orig).data("pcpiBusy", false);
					alert((res && res.data && res.data.message) ? res.data.message : "Delete failed.");

				}

			})
			.fail(function () {
				btn.prop("disabled", false).text(orig).data("pcpiBusy", false);
				alert("Delete failed (request error).");
			});
		});

});