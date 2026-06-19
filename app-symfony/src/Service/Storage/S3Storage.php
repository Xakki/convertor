<?php

declare(strict_types=1);

namespace App\Service\Storage;

use AsyncAws\S3\Input\GetObjectRequest;
use AsyncAws\S3\S3Client;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Thin wrapper around the S3 result bucket. All async-aws API usage is confined
 * here so the rest of the app stays library-agnostic. Result bucket =
 * `{S3_BUCKET_PREFIX}-results`; objects are keyed `results/{Y}/{m}/{d}/{id}.{ext}`
 * (the worker emits the exact key in the result event — we store and reuse it).
 */
final class S3Storage
{
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucketPrefix,
    ) {}

    public function resultsBucket(): string
    {
        return $this->bucketPrefix . '-results';
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
        $response = new StreamedResponse(static function () use ($output): void {
            foreach ($output->getBody()->getChunks() as $chunk) {
                echo $chunk;
                flush();
            }
        });

        $response->headers->set('Content-Type', $mime);
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        );

        return $response;
    }
}
