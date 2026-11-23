=== AVPVH Audio Transcriptie ===
Contributors: grmt
Tags: audio, transcription, speech-to-text, editor, accessibility
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 0.8.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Transcribe audio progressively, correct speaker segments, keep revision history, and optionally request a summary.

== Description ==

AVPVH Audio Transcriptie adds a transcription workspace to WordPress administration. An authorized user can upload audio, edit segments while background transcription continues, identify speakers and groups, play the focused fragment, and restore earlier corrections.

The plugin is an open-source client for a separately hosted transcription service. It does not include a speech-to-text model or service. Nothing is transmitted until an administrator explicitly configures a service URL and starts a job.

= External service disclosure =

To provide transcription and optional summaries, the plugin sends the following data to the transcription-service URL and, when separately configured, the summary API URL chosen by a site administrator:

* the selected audio file;
* its file name, size, MIME type, requested language, and model;
* corrected transcript segments, speaker labels, group labels, and a summary instruction;
* the applicable configured service or summary API key in an Authorization header.

The administrator chooses and controls the service endpoint. This project does not prescribe or silently contact a particular provider. Before using an endpoint, the administrator must review that provider's terms and privacy policy and ensure there is a lawful basis for processing the recording and participant data. Removing the service URL disables new service requests.

= Main features =

* Direct browser upload to a temporary URL supplied by the configured service.
* Progressive transcript updates while the editor remains usable.
* Automatic saving with optimistic revision locking.
* Up to 50 locally stored correction revisions per transcript.
* Speaker and group labels derived from the current transcript, with no built-in identities.
* Keyboard playback: Space outside form controls or Control+Space anywhere.
* Playback-synchronized segment focus and sentence deletion with revision recovery.
* Optional summary through the transcription service or a separately configured OpenAI-compatible LLM endpoint.
* Standalone interactive HTML export.
* Read-only page embedding with `[avpvh_audio_transcriptie id="1"]`.

== Installation ==

1. Upload the plugin directory to `/wp-content/plugins/` or install its ZIP file.
2. Activate the plugin.
3. Open **Transcripties > Instellingen**.
4. Enter the HTTPS URL and service key for a compatible transcription service.
5. Validate the configuration and start a transcription.

The service key can instead be kept in `wp-config.php`:

`define( 'AVPVH_AT_LICENSE_KEY', 'your-service-key' );`

== Frequently Asked Questions ==

= Does this plugin include Whisper or another transcription engine? =

No. It is a WordPress editor and service client. The configured endpoint performs transcription.

= Does the plugin send data automatically? =

No. An administrator must configure an endpoint, and an authorized user must select a file and start a job.

= Where are corrections stored? =

Transcript segments and their last 50 revisions are stored in custom tables in the WordPress database. Audio is uploaded directly to the configured service; WordPress stores only the returned playback URL.

= What API does a service need to implement? =

See `docs/service-api.md` in the public source repository linked from the plugin header.

== Privacy ==

This plugin can process voice recordings, transcript text, speaker labels, and group labels. These may contain personal data. The plugin adds suggested text to the WordPress privacy policy guide. Site administrators remain responsible for access control, retention, participant information, consent or another lawful basis, and the terms of their configured service.

== Changelog ==

= 0.8.3 =

* Fix search, speaker and party filters not hiding rows in the editor and on public pages.
* Call the WordPress AI Client with its prompt-builder API (system instruction plus user text).
* Never copy keys defined in wp-config.php into the database; keep percent-encoded keys and upload headers intact.
* Prevent lost edits: atomic revision checks, refresh merges against the latest row, tombstones for deleted fragments, and safer saving, polling and navigation in the editor.
* Enable summaries when only the transcription service or a keyless LLM API is configured, and load background summaries on completed jobs.
* Hide publishing and summary controls from users without permission; allow repeated page updates and move or relabel existing block-navigation links.
* Keep keyboard activation of buttons and links working; the Space shortcut only toggles audio outside controls.
* Smaller job-list queries, no full editor re-render on insert or poll, a clean build directory, and JavaScript checks for both scripts.
* Require PHP 8.4, the floor of the shared plugin CI and the servers.
* Run CI through the shared grmt/wp-plugin-ci checks (PHPStan level 8, Plugin Check, prefix, naming) and release from the CI build.

= 0.8.2 =

* Split summary guidance into separate procedural and content-specific prompts.
* Allow both prompts to be configured globally and adjusted before each summary request.
* Prefer WordPress AI Client with a configured provider and retain the direct LLM endpoint as fallback.

= 0.8.1 =

* Present publishing, summary, and transcript content as three ordered editor sections.
* Keep publishing and summary collapsed initially and place search inside the open transcript section.
* Remove duplicate header actions and place shortcode copying inside the publishing section.

= 0.8.0 =

* Group transcript, export, and publication actions into compact menus.
* Add and remove sentences directly beside each transcript row.
* Make the transcript title directly editable and explain which stored files retain their original names.
* Show and copy the page shortcode from the publication panel.
* Surface missing summary configuration and support block-theme navigation menus.

= 0.7.0 =

* Rename transcripts from a non-blocking editor panel.
* Show audio, database, revision, export, and page storage information.
* Replace the blocking summary prompt with an inline editable panel.
* Export the corrected transcript as a Markdown table.

= 0.6.0 =

* Create or update a live WordPress transcript page from the editor.
* Optionally place the published page in a selected navigation menu and below an existing menu item.
* Focus and scroll to the first matching transcript segment while searching.

= 0.5.0 =

* Keep the focused transcript segment synchronized with audio playback.
* Delete the focused sentence with autosave and revision-history recovery.
* Export the corrected transcript as a standalone interactive HTML file.
* Configure a separate OpenAI-compatible LLM endpoint, model, and API key for summaries.

= 0.4.1 =

* Show existing transcripts before the collapsed new-transcription form.

= 0.4.0 =

* Added global and per-job selection of provider, model, language, prompt, glossary, and chunk duration.
* Kept authenticated read routes available when a site-wide content restriction blocks the REST API.

= 0.3.0 =

* Made the transcript list and read-only editor available to every signed-in WordPress user.
* Reduced the initial transcript-list payload for faster loading.

= 0.2.0 =

* Added a read-only frontend shortcode with audio, search, speaker filters, and group filters.

= 0.1.0 =

* Initial public development release.

== Upgrade Notice ==

= 0.8.3 =

Fixes data-loss races in the editor, broken search filters, and WordPress AI Client summaries. Adds a database column, applied automatically. Requires PHP 8.4.

= 0.8.2 =

Adds separate procedural and content-specific guidance for summaries.

= 0.8.1 =

Clarifies the editor hierarchy and removes duplicate publishing and summary controls.

= 0.8.0 =

Adds a compact contextual editor, visible shortcode guidance, clearer summary configuration, and block-navigation publishing.

= 0.7.0 =

Adds transcript renaming, storage information, non-blocking summary instructions, and Markdown export.

= 0.6.0 =

Adds guided WordPress page publication, menu placement, and clearer transcript search behavior.

= 0.5.0 =

Adds playback synchronization, sentence deletion, HTML export, and configurable LLM summaries.

= 0.4.1 =

Existing transcripts are immediately visible at the top of the editor page.

= 0.4.0 =

Configure transcription defaults and override them for each new recording.

= 0.3.0 =

All signed-in WordPress users can now view transcripts; editing still requires upload permission.

= 0.2.0 =

Adds the frontend transcript shortcode.

= 0.1.0 =

Initial public development release; configure a compatible service before creating a job.
