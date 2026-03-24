```md
# PCPI Staff Dashboard (Gravity Forms Workflow)

WordPress plugin that powers the **PCPI Polygraph Staff Dashboard** workflow built on **Gravity Forms** + **Gravity PDF**.

It provides:

- A front-end **Staff Dashboard table** via shortcode: `[gf_entries_table]`
- Staff actions per applicant:
  - **Review** (opens examiner review form)
  - **Summary** (opens Gravity PDF summary)
  - **Send PDF** (emails a secure PDF link to the agency)
  - **Resend** (re-sends the applicant questionnaire link notification, optionally to a corrected email)
  - **Delete** (cascade deletes related GF entries)
- **Signed, expiring links** for:
  - the **Applicant questionnaire** (Form 2)
  - the **Review PDF download** (Form 23 via Gravity PDF, streamed privately)

---

## Requirements

- WordPress 6.x+
- PHP 8.0+ (recommended)
- **Gravity Forms** (required)
- **Gravity PDF** (required for PDF streaming + sending secure links)
- A staff role/capability that includes: `gf_manage_entries` (or administrators)

---

## Installation

1. Upload this plugin to `/wp-content/plugins/pcpi-staff-dashboard/`
2. Ensure the plugin structure includes:

```

pcpi-staff-dashboard/
pcpi-staff-dashboard.php   (main plugin file)
assets/
css/gf-entries-table.css
js/gf-entries-table.js

````

3. Activate the plugin in **Plugins → Installed Plugins**
4. Add the shortcode to your Staff Dashboard page.

---

## Usage

### Shortcode

```txt
[gf_entries_table form_id="1" last_name_field="1.6" first_name_field="1.3" limit="25" debug="no"]
````

**Parameters**

* `form_id` (default `1`)
  Applicant form ID (Form 1)
* `last_name_field` (default `1.6`)
  The Form 1 “Last Name” input ID
* `first_name_field` (default `1.3`)
  The Form 1 “First Name” input ID
* `limit` (default `100`)
  Entries per page
* `page_var` (default `pcpi_page`)
  Query arg used for pagination (example: `?pcpi_page=2`)
* `debug` (`yes|no`, default `no`)
  Shows derived IDs and state per row

---

## How the workflow works

### Forms

This plugin assumes the PCPI workflow forms:

* **Form 1**: Applicant (parent)
* **Form 2**: Questionnaire (child)
* **Form 23**: Examiner Review (child-of-questionnaire)

The plugin links these forms using **hidden relational fields** (configured below).

### Derived Statuses (no DB writes)

Status is computed on the fly per applicant row:

* `pending` — no questionnaire entry found
* `submitted` — questionnaire exists but not marked “review ready”
* `ready` — review-ready but no review entry exists
* `reviewed` — review exists but PDF not yet available
* `pdf` — PDF is available

Displayed as pill badges in the dashboard.

---

## Configuration

All key IDs live in `pcpi_polygraph_gf_config()`:

```php
'PARENT_FORM_ID'        => 1,
'QUESTIONNAIRE_FORM_ID' => 2,
'REVIEW_FORM_ID'        => 23,
'REVIEW_FORM_IDS'       => [23],

'Q_PARENT_APPLICANT_EID_FID' => '579',
'Q_REVIEW_DONE_FLAG_FID'     => '559',

'REVIEW_PARENT_Q_EID_FID' => '643',
'REVIEW_PARENT_A_EID_FID' => '608',

'PDF_ID' => '690f9d2e167ec',

'RESEND_NOTIFICATION_NAME' => 'Questionnaire link',

'AGENCY_NAME_FID'  => '6',
'AGENCY_EMAIL_FID' => '7',

'PDF_LINK_TTL_DAYS' => 7,
```

### What you’ll most likely change

* Field IDs for:

  * Questionnaire → parent applicant entry ID
  * Questionnaire “review ready” flag
  * Review → parent questionnaire entry ID
  * Review → parent applicant entry ID (optional fallback)
* `PDF_ID` (Gravity PDF configuration ID)
* Agency name/email field IDs (Form 1)
* Review form URL (see below)

---

## URLs used by the plugin

### Review button (examiner review form)

The Review button builds:

* `/form-polygraph-questionnaire-examiner-review/?parent_questionnaire_entry_id={QID}&parent_applicant_entry_id={AID}`

If your review form page slug differs, update the URL in the shortcode renderer where `$review_url` is built.

### Staff Help link

The dashboard header includes a “Help” link.

* Default: `/staff-help/`
* Filterable:

```php
add_filter('pcpi_staff_help_url', function($default){
  return site_url('/your-help-page/');
});
```

---

## Secure Links

### 1) Secure questionnaire link (Applicant → Form 2)

This plugin defines:

* `pcpi_build_secure_questionnaire_link( $applicant_entry_id )`

It generates a signed URL to:

* `/form-polygraph-questionaire/?parent_applicant_entry_id=...&pcpi_exp=...&pcpi_nonce=...&pcpi_sig=...`

> Note: the path uses `/form-polygraph-questionaire/` (spelling matches existing system). Keep it consistent with your site.

#### Merge tag

In Form 1 notifications, use:

* `{pcpi_questionnaire_link}`

The plugin replaces it automatically for **Form 1 only**.

### 2) Secure PDF link (Agency download)

This plugin defines:

* `pcpi_build_secure_pdf_link( $review_entry_id )`

It generates:

* `/?pcpi_pdf_dl=1&rid=123&pcpi_exp=...&pcpi_nonce=...&pcpi_sig=...`

A `template_redirect` handler validates signature + expiry, then uses **Gravity PDF API** to generate and stream the PDF file privately:

* `GPDFAPI::create_pdf( $rid, $pdf_id )`

After streaming, the temp PDF is deleted.

---

## AJAX Actions

All AJAX endpoints require:

* logged-in user
* capability: `gf_manage_entries` or `manage_options`
* valid nonce (provided via `wp_localize_script`)

### Resend applicant questionnaire link

Action: `gf_resend_entry`

* Resends only the notification named by:

  * `RESEND_NOTIFICATION_NAME` (default: `"Questionnaire link"`)
* Supports optional POST field:

  * `override_email` (forces that notification’s “to” address)

### Delete applicant (cascade)

Action: `gf_delete_entry`

When deleting a **Form 1 applicant** entry, it also deletes:

* Form 2 questionnaire entries related to applicant
* Form 23 review entries related to those questionnaires
* (Optional fallback) review entries related directly to the applicant

> ⚠️ This deletes Gravity Forms entries only.
> It does **not** remove previously generated/stored PDFs if you have any persistent PDF storage outside the “stream and delete temp file” approach.

### Send PDF link to agency

Action: `pcpi_send_pdf_link`

* Builds secure PDF link for a given Review entry ID
* Emails the agency using `wp_mail()` as HTML
* Pulls agency name/email from Form 1 by default
* Allows optional overrides:

  * `to_name`, `to_email`

---

## Front-end Assets

The plugin enqueues:

* `dashicons` (for the help icon)
* `assets/css/gf-entries-table.css`
* `assets/js/gf-entries-table.js`
* `jquery`

It also localizes `PCPI_GF` with:

* `ajaxUrl`
* nonces
* confirm messages
* Add Applicant modal configuration (desktop only)

---

## Add Applicant Modal (desktop only)

The dashboard outputs a hidden modal containing:

```txt
[gravityforms id="1" title="false" description="false" ajax="true"]
```

On mobile, JS should **not** intercept the “Add Applicant” button (it should navigate normally), controlled by localized settings:

* `addApplicantDisableMobile: true`
* `addApplicantMobileMaxWidth: 782`

---

## Filters

### Staff help page URL

```php
add_filter('pcpi_staff_help_url', fn($default) => site_url('/staff-help/'));
```

### Applicant email field ID override (optional)

By default, the plugin tries to detect the first Email field in Form 1.
You can override it:

```php
add_filter('pcpi_applicant_email_field_id', function($override, $form){
  return 12; // your Form 1 email field ID
}, 10, 2);
```

---

## Security Notes

* All secure links use:

  * expiry timestamp (`pcpi_exp`)
  * nonce (`pcpi_nonce`)
  * HMAC signature (`pcpi_sig`) with `wp_salt('pcpi_polygraph_links')`
* PDF streaming disables display errors to avoid corrupting PDF output
* AJAX routes validate both nonce and capability

---

## Troubleshooting

### “Gravity Forms is not active.”

Activate Gravity Forms and ensure `GFAPI` is available.

### Buttons are disabled

Buttons only enable when upstream workflow state exists:

* Review requires:

  * Questionnaire entry exists AND review-ready flag is set
* Summary requires:

  * Review entry exists (PDF URL can be derived)
* Send PDF requires:

  * Review entry exists AND PDF is ready

### Agency email missing

Confirm Form 1 fields `AGENCY_NAME_FID` and `AGENCY_EMAIL_FID` match your form.

### PDF endpoint shows “Gravity PDF API not available.”

Make sure Gravity PDF is installed/activated and `GPDFAPI` exists.

---

## Changelog

### 1.0.8

* Pagination UI improvements
* Status column + status pills
* Staff help link (filterable)
* Dashicons loaded on front-end
* Resend modal pre-fills applicant email (auto-detected, filterable)
* Secure PDF link emailed to agency (signed, expiring, streamed privately)

---

## License

GPLv2 or later.

---

## Authors

Gregg Franklin, Marc Benzakein

```
```
