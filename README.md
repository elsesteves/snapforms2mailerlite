# SnapForms2MailerLite

MailerLite Integration for [SnapForm WordPress Plugin](https://snapforms.tech/) submissions.

## Overview
This WordPress plugin adds subscribers to MailerLite triggered by SnapForms submission creation events. It supports multiple SnapForms forms, each with its own mapping, consent requirements, and target groups, configured via a JSON file stored outside the plugin directory.

## Key Features
- Per-form configuration (multiple forms supported)
- Field mapping from SnapForms fields to MailerLite custom fields
- Consent enforcement (one or more consent fields required)
- Group assignment on subscribe
- Config stored outside the plugin (safer for updates)
- Graceful error handling (errors logged via `error_log`)

## Requirements
- WordPress
- SnapForms plugin (to emit submission events)
- A MailerLite API token

## How It Works
- On each SnapForms submission, the plugin receives `snapforms.submission.new`.
- It looks up the form's config by `id_form`.
- If required consents are present, it builds the MailerLite payload using your field mappings and submits it to MailerLite.

## Installation
1. Copy this plugin folder into `wp-content/plugins/`.
2. Activate the plugin from the WordPress admin.
3. On first activation, if no config exists, the plugin generates a sample config file at:
   `wp-content/snapforms/addons/mailerlite/config.json`
4. Edit the config file to add your real data (API token, form IDs, field mappings, consent fields, and groups).

## Configuration Location
The configuration file is stored outside the plugin, so updates don’t overwrite your settings:
- Path: `wp-content/snapforms/addons/mailerlite/config.json`

## Config Schema
Top-level object with a `forms` array. Each entry corresponds to a SnapForms form and must include an `id_form` string.

- `id_form` (string): The SnapForms form ID this config applies to.
- `api_token` (string): Your MailerLite API token.
- `fields` (object): Map of SnapForms field IDs → MailerLite field names.
- `required_consents` (array): List of SnapForms field IDs that must have consent `dt_given`.
- `groups` (array): Array of MailerLite group IDs to add the subscriber to.
- `settings` (object): Optional settings.
  - `timeout` (number): HTTP timeout in seconds (default: 30).

### Example Config
```json
{
  "forms": [
    {
      "id_form": "SNAPFORMS_FORM_ID",
      "api_token": "MAILERLITE_API_TOKEN",
      "fields": {
        "SNAPFORMS_FIELD_UUID_A": "MAILERLITE_FIELD_NAME_A",
        "SNAPFORMS_FIELD_UUID_B": "MAILERLITE_FIELD_NAME_B",
        "SNAPFORMS_FIELD_UUID_C": "MAILERLITE_FIELD_NAME_C"
      },
      "required_consents": [
        "SNAPFORMS_FIELD_UUID"
      ],
      "groups": [
        "MAILERLITE_GROUP_ID"
      ],
      "settings": {
        "timeout": 30
      }
    }
  ]
}
```

## Notes on Field Values
For each mapped SnapForms field, the plugin attempts to read, in order:
- `sub_data.label`
- `sub_data.value`
- `sub_data.text`
If none are present, an empty string is sent.

## Required Consents
- Each field UUID in `required_consents` must be a consent checkbox field, containing `sub_data.consent.dt_given` (non-empty) in the submission. If any required consent is missing, the subscriber is not added.

## Multiple Forms
- Add an entry to the `forms` array for each SnapForms form you want to integrate.
- The plugin indexes these entries internally by `id_form` for fast lookups.

## Error Handling
- API request failures and validation errors are logged with `error_log`.
- No admin notices are displayed by default to avoid noise on normal submissions.

## Security & Best Practices
- Keep your `api_token` out of version control.
- Never commit real tokens to public repositories.
- Ensure `wp-content/snapforms/addons/mailerlite/` is writable by WordPress to allow initial sample config creation.

## Uninstallation
- Removing the plugin does not delete the external config directory.
- If you need to remove configuration, delete `wp-content/snapforms/addons/mailerlite/config.json` manually.

## Developers
- Hook consumed: `snapforms.submission.new`
- Core client: `includes/MailerLiteClient.php`
- The client supports the current JSON shape with `fields` and optional `settings.timeout`. It can be extended to support new keys (e.g., global defaults) with minimal changes.

## Changelog
### 1.0.0
- Initial public release: per-form configs, consent enforcement, external config storage, sample config generation on activation.

## Credits
- Author: Eduardo Esteves
- Project page: https://edluis97.github.io/
