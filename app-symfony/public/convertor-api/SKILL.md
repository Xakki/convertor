---
name: convertor-api
description: Convert documents, images, audio, video, structured data, and text through the API, including OCR, transcription, and text-to-speech.
compatibility: Requires network access to https://convertor.xakki.pro and an HTTP client.
metadata:
  author: xakki
  version: "1.1"
  tags: "file-conversion, document-conversion, image-conversion, audio-conversion, video-conversion, data-conversion, text-conversion, ocr, transcription, text-to-speech"
---

# Convertor API

Use the public Convertor REST API for asynchronous file and text conversion.
Swagger UI is available at https://convertor.xakki.pro/api/doc.

## Mandatory freshness rule

**Before every use of the API, first fetch and parse the current absolute
OpenAPI schema:**

https://convertor.xakki.pro/api/doc.json

Do not rely on a cached schema or a static list of endpoints, fields, response
codes, supported pairs, or authentication rules. If the schema cannot be fetched
or parsed, do not make API requests; report that the operation is blocked.

For the intended operation, inspect its HTTP method, `requestBody`, `parameters`,
`security`, `responses`, and response type. Use only the absolute base URL
`https://convertor.xakki.pro`. Then query the format-list operation from the
fresh schema and confirm the exact source and target pair, including advertised
AI or OCR properties.

## Authentication and first use

Authentication is optional for guest-compatible operations. Resolve credentials
in this order:

1. Use `CONVERTOR_TOKEN` from the process environment or runtime secret store;
   the environment variable takes precedence.
2. Otherwise, read the local credential overlay at
   `${XDG_CONFIG_HOME:-$HOME/.config}/convertor-api/token`. Treat it as valid
   only when the runtime opens it with no-follow semantics and then verifies on
   the opened file descriptor that it is an owned regular non-symlink file not
   accessible by group or others. This check and read must use that same
   descriptor to avoid a path-check race. If the runtime cannot provide these
   guarantees, ignore it and offer guest mode or external configuration. Read
   it without printing, logging, or interpolating its value into user-visible
   output.
3. If neither source is configured, make a first-use choice: offer to continue
   as a guest or configure authentication outside the chat.

Do not ask the user to paste a token into chat, a command, a URL, source code, or
this skill.

An authorization secret must never appear in command arguments or a process
listing. Prefer an in-memory HTTP client that keeps the header value in process
memory. If curl is unavoidable, use a private curl config supplied through
standard input and ensure command tracing is disabled; never put an
authorization header or token in a literal command-line option.

Read the fresh operation-level `security` and format metadata before deciding.
For a guest-compatible operation, continue without a token after the user has
chosen guest mode and preserve one private cookie jar through create, status,
and download requests. For an authentication-required operation, do not attempt
a guest request: explain that authentication is required and ask the user to
configure the environment variable, runtime secret store, or credential overlay
outside the chat, then retry after configuration.

Never disclose tokens or cookies in command transcripts, logs, errors, or final
responses. Never copy credentials into a skill installation. Do not call
administrative, billing, or internal operations without an explicit task and
appropriate permission.

## Conversion procedure

1. Fetch and parse `https://convertor.xakki.pro/api/doc.json` immediately before
   any API use.
2. Confirm the required operation and exact format pair against live data.
3. Resolve optional authentication as described above. For guest mode, create a
   private temporary cookie jar and reuse it for every request in the job.
4. If the live schema exposes quota information, check it before a large or
   resource-intensive upload and honor the returned limits.
5. Build the create request exactly from the fresh `requestBody`. Do not infer
   field names from an old example.
6. Store the conversion identifier from the accepted asynchronous response.
7. Poll the documented status operation at a moderate interval with a bounded
   wait. Stop on every terminal state; do not retry or resubmit automatically.
8. Download only after the status operation reports `completed`. Save promptly,
   then verify the HTTP status, `Content-Type`, filename, and nonzero size before
   reporting success.
9. Report the selected pair, final status, and result path without sensitive
   headers, cookies, or response bodies.

## Operational caveats

The following behavior has been rechecked against the live contract, but the
fresh schema remains authoritative:

- The create operation is asynchronous and currently returns HTTP `202` with a
  conversion identifier; it does not return the converted file.
- Poll the dedicated status endpoint. A successful create response is not a
  completion signal.
- Download only after `completed`; otherwise an error payload can be mistaken
  for an output file.
- A guest conversion is session-bound. Reuse the same private cookie jar for
  create, status, and download, and remove the jar when finished.
- Download promptly after completion because result retention is finite.
- AI work can remain pending or processing longer than ordinary conversion.
  Poll moderately, use a bounded wait, and never create a tight retry loop.
- Validate completion through job status and validate the downloaded response
  by HTTP status, content type, and nonzero size.

## Error handling

Handle documented responses individually. For `401` or `403`, recheck live
security requirements and request safe external configuration when necessary;
do not solicit a token in chat. For rate limits, honor `Retry-After` when
present. For upload, validation, or server errors, recheck the fresh schema,
avoid unbounded retries, and report a safe error without secrets.

## Completion checklist

The task is complete only when:

- `https://convertor.xakki.pro/api/doc.json` was fetched immediately before use;
- the operation, request, security, and responses were checked against it;
- the exact pair was confirmed through the current format registry;
- optional authentication followed the documented precedence and first-use flow;
- the job reached `completed` before download;
- the downloaded result was validated as a nonempty file, not an error payload;
- no credential or cookie appeared in commands, logs, files, or the response.
