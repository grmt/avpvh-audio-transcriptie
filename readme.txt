=== AVPVH Audio Transcriptie ===
Contributors: grmt
Tags: audio, transcription, speech-to-text, editor, accessibility
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Transcribe audio progressively, correct speaker segments, keep revision history, and optionally request a summary.

== Description ==

AVPVH Audio Transcriptie adds a transcription workspace to WordPress administration. An authorized user can upload audio, edit segments while background transcription continues, identify speakers and groups, play the focused fragment, and restore earlier corrections.

The plugin is an open-source client for a separately hosted transcription service. It does not include a speech-to-text model or service. Nothing is transmitted until an administrator explicitly configures a service URL and starts a job.

= External service disclosure =

To provide transcription and optional summaries, the plugin sends the following data to the service URL configured by a site administrator:

* the selected audio file;
* its file name, size, MIME type, requested language, and model;
* corrected transcript segments, speaker labels, group labels, and a summary instruction;
* the configured service key in an Authorization header.

The administrator chooses and controls the service endpoint. This project does not prescribe or silently contact a particular provider. Before using an endpoint, the administrator must review that provider's terms and privacy policy and ensure there is a lawful basis for processing the recording and participant data. Removing the service URL disables new service requests.

= Main features =

* Direct browser upload to a temporary URL supplied by the configured service.
* Progressive transcript updates while the editor remains usable.
* Automatic saving with optimistic revision locking.
* Up to 50 locally stored correction revisions per transcript.
* Speaker and group labels derived from the current transcript, with no built-in identities.
* Keyboard playback: Space outside form controls or Control+Space anywhere.
* Optional service-generated summary.
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

= 0.2.0 =

* Added a read-only frontend shortcode with audio, search, speaker filters, and group filters.

= 0.1.0 =

* Initial public development release.

== Upgrade Notice ==

= 0.2.0 =

Adds the frontend transcript shortcode.

= 0.1.0 =

Initial public development release; configure a compatible service before creating a job.
