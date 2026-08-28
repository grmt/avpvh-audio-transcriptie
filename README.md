# AVPVH Audio Transcriptie

[![GitHub Actions Workflow Status](https://img.shields.io/github/actions/workflow/status/grmt/avpvh-audio-transcriptie/quality.yml?branch=main&logo=github)](https://github.com/grmt/avpvh-audio-transcriptie/actions)
[![GitHub License](https://img.shields.io/github/license/grmt/avpvh-audio-transcriptie)](https://github.com/grmt/avpvh-audio-transcriptie/blob/main/LICENSE)

Open-source WordPress plugin for progressive audio transcription, correction history, speaker/group labelling, playback-assisted editing, and optional summaries.

This repository contains only generic plugin source. It contains no bundled recordings, transcripts, participant identities, organization identities, service credentials, or predetermined service provider.

## Status

This is an early development release. The WordPress client and editor are implemented; a compatible transcription service is required and is not part of this repository. The administrator explicitly supplies that service URL and is responsible for its terms, privacy policy, and data-processing basis.

## Features

- Edit already available segments while transcription continues in the background.
- Automatically save manual corrections without letting later service updates overwrite them.
- Restore any of the 50 most recent correction revisions.
- Add a sentence; selected text is moved from the current segment to the inserted segment.
- Assign free-form speakers and groups without hard-coded identities.
- Play the focused fragment and navigate filtered results with the keyboard.
- Upload audio directly to a temporary service URL rather than the public media library.
- Request an optional summary after correction.
- Embed a read-only transcript on a page with `[avpvh_audio_transcriptie id="1"]`.

## Install for development

1. Copy or symlink the repository into `wp-content/plugins/avpvh-audio-transcriptie`.
2. Activate **AVPVH Audio Transcriptie**.
3. Configure a compatible HTTPS endpoint under **Transcripties → Instellingen**.

For production, install the ZIP produced by the release workflow. Development tools and CI configuration are excluded from that archive.

## Development checks

```bash
composer install
composer check
npm run check
```

GitHub Actions additionally runs the official WordPress Plugin Check against the clean distribution directory. See [the service API contract](docs/service-api.md) to implement or connect a worker.

## WordPress.org readiness

The project includes:

- WordPress plugin headers and a matching `readme.txt` stable tag;
- GPL-2.0-or-later licensing;
- WordPress Coding Standards through PHP_CodeSniffer;
- the official WordPress Plugin Check action;
- PHP syntax and JavaScript syntax checks;
- a clean distribution build with source audio, transcripts, local configuration, and development files excluded;
- explicit external-service and privacy disclosures.

Passing CI is a technical release gate, not a guarantee of acceptance. Before submitting to WordPress.org, the chosen service deployment must have public terms and privacy information, and the plugin slug must be approved.

## Sister project

This project follows the same open-source repository family as [AVPVH Gallery](https://github.com/grmt/avpvh-gallery). No runtime dependency exists between the projects.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
