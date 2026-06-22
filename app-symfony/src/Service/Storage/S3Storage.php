<?php

declare(strict_types=1);

namespace App\Service\Storage;

use AsyncAws\S3\Input\GetObjectRequest;
use AsyncAws\S3\Input\PutObjectRequest;
use AsyncAws\S3\Result\GetObjectOutput;
use AsyncAws\S3\S3Client;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Thin wrapper around the S3 result bucket. All async-aws API usage is confined
 * here so the rest of the app stays library-agnostic. Result bucket =
 * `{S3_BUCKET_PREFIX}-results`; objects are keyed `results/{Y}/{m}-{d}/{id}.{ext}`
 * (the worker emits the exact key in the result event — we store and reuse it).
 */
final class S3Storage
{
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucketPrefix,
    ) {
    }

    public function resultsBucket(): string
    {
        return $this->bucketPrefix . '-results';
    }

    public function inputsBucket(): string
    {
        return $this->bucketPrefix . '-inputs';
    }

    /**
     * PUT an object into the given bucket. Body may be a string (content) or a
     * stream/resource (e.g. fopen() of the upload tmp file). Forces the async
     * result to resolve so bucket/auth errors surface synchronously here —
     * before the caller dispatches a message that references the object.
     *
     * @param string|resource $body
     */
    public function putObject(string $bucket, string $key, $body, ?string $contentType = null): void
    {
        $input = [
            'Bucket' => $bucket,
            'Key'    => $key,
            'Body'   => $body,
        ];

        if ($contentType !== null) {
            $input['ContentType'] = $contentType;
        }

        $this->client->putObject(new PutObjectRequest($input))->resolve();
    }

    /**
     * Stream an object from the given bucket directly to the HTTP response.
     * Used by the worker pull-API to proxy input files to off-server workers.
     * Calls resolve() eagerly so S3 errors (NoSuchKey, auth) surface here —
     * before the response is sent — letting the controller return a clean JSON error.
     */
    public function streamFromBucket(string $bucket, string $key): StreamedResponse
    {
        $output = $this->client->getObject(new GetObjectRequest([
            'Bucket' => $bucket,
            'Key'    => $key,
        ]));

        $output->resolve();

        $response = $this->streamBody($output);
        $response->headers->set('Content-Type', $output->getContentType() ?? 'application/octet-stream');

        return $response;
    }

    /**
     * Authenticated streaming proxy: pulls the object from S3 and streams it to
     * the client (per-user access check stays in the controller).
     */
    public function downloadResponse(string $key, string $filename, string $mime): StreamedResponse
    {
        $output = $this->client->getObject(new GetObjectRequest([
            'Bucket' => $this->resultsBucket(),
            'Key'    => $key,
        ]));

        // NOTE: verify `getChunks()` against the installed async-aws/s3 v2 at
        // deploy. If absent, the alternatives are `foreach ($output->getBody() ...)`
        // (ResultStream is iterable) or `fpassthru($output->getBody()->getContentAsResource())`.
        $response = $this->streamBody($output);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        );

        return $response;
    }

    private function streamBody(GetObjectOutput $output): StreamedResponse
    {
        return new StreamedResponse(static function () use ($output): void {
            foreach ($output->getBody()->getChunks() as $chunk) {
                echo $chunk;
                flush();
            }
        });
    }
}
