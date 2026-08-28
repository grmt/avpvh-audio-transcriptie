# Transcription service API

The WordPress plugin talks only to the base URL an administrator configures. All control requests use HTTPS JSON with `Authorization: Bearer <service-key>`.

## Endpoints

### `POST /v1/license/validate`

Returns:

```json
{ "valid": true }
```

### `POST /v1/jobs`

Receives file metadata and the complete transcription configuration selected in WordPress:

```json
{
  "filename": "meeting.m4a",
  "size": 123456,
  "content_type": "audio/mp4",
  "provider": "faster-whisper",
  "language": "nl",
  "model": "medium",
  "prompt": "Transcribe accurately in Dutch.",
  "glossary": "Example term, Product name",
  "chunk_seconds": 600
}
```

The service must use these values for that job. `prompt` and `glossary` may be empty. `chunk_seconds` is constrained by the plugin to 60–3600 seconds. The service returns a stable job identifier and a short-lived upload target:

```json
{
  "job_id": "stable-job-id",
  "upload_url": "https://temporary-upload.example/object",
  "upload_headers": {},
  "playback_url": ""
}
```

The upload target must accept an HTTP `PUT` containing the selected file and should be restricted to that object, size, and a short lifetime.

### `POST /v1/jobs/{id}/upload-complete`

Confirms that the direct upload finished and queues background work.

### `GET /v1/jobs/{id}`

Returns progressive state:

```json
{
  "status": "transcribing",
  "progress": 42.5,
  "duration": 600.0,
  "playback_url": "https://temporary-playback.example/object",
  "segments": [
    {
      "id": "stable-segment-id",
      "start": 0.0,
      "end": 2.5,
      "text": "Example text.",
      "speaker": "",
      "party": ""
    }
  ]
}
```

Segment IDs must remain stable across polling responses. Status is one of `created`, `uploading`, `queued`, `converting`, `transcribing`, `complete`, or `failed`. Playback URLs should be short-lived and access-controlled.

### `POST /v1/jobs/{id}/summary`

Receives the current corrected segments and a summary instruction. It either returns `{"summary":"..."}` or acknowledges background generation; a later job response may then contain `summary`.

Alternatively, administrators can configure a separate OpenAI-compatible chat-completions URL, model, and API key in WordPress. In that mode the plugin sends the corrected transcript directly to that endpoint and stores the returned summary. The summary key is independent from the transcription-service key and can also be supplied through `AVPVH_AT_SUMMARY_API_KEY` in `wp-config.php`.

## Required service safeguards

- Authenticate every control request and authorize access to each job.
- Use encrypted transport and private object storage.
- Define retention, deletion, rate limits, accepted media types, and maximum sizes.
- Treat recordings, transcripts, speaker labels, and summaries as potentially sensitive personal data.
- Never return executable code to the plugin.
- Publish operator identity, terms, privacy information, and subprocessors before offering the endpoint to third parties.
