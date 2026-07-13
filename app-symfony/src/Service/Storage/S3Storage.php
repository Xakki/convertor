<?php

declare(strict_types=1);

namespace App\Service\Storage;

use AsyncAws\S3\Input\DeleteObjectRequest;
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
     * DELETE an object из указанного бакета. Форсирует resolve() — как putObject —
     * чтобы ошибки (auth, недоступность S3) всплывали синхронно здесь. Удаление
     * несуществующего ключа для S3/MinIO идемпотентно (success, без NoSuchKey), так
     * что «объект уже удалён» ошибкой не считается; ловим только реальные сбои.
     */
    public function deleteObject(string $bucket, string $key): void
    {
        $this->client->deleteObject(new DeleteObjectRequest([
            'Bucket' => $bucket,
            'Key'    => $key,
        ]))->resolve();
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
        $response->headers->set('Content-Disposition', self::contentDisposition($filename));

        return $response;
    }

    /**
     * Строит заголовок Content-Disposition (attachment) для отдачи файла.
     *
     * makeDisposition требует ASCII-совместимый fallback: если его не передать, Symfony
     * берёт fallback = само имя и падает InvalidArgumentException на любом не-ASCII имени
     * (кириллица и т.п.) → неперехваченное исключение → HTTP 500 «Не удалось скачать».
     * Поэтому явно передаём транслитерированный ASCII-fallback (для legacy-браузеров), а
     * оригинальное UTF-8 имя уходит в `filename*` (RFC 5987) — современные браузеры видят
     * настоящее (в т.ч. кириллическое) имя.
     */
    public static function contentDisposition(string $filename): string
    {
        return HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
            self::asciiFallback($filename),
        );
    }

    /**
     * ASCII-safe fallback для `filename` в Content-Disposition. Транслитерирует
     * не-ASCII (кириллицу → латиницу через intl, если доступен), затем оставляет только
     * безопасный набор `[A-Za-z0-9._-]` (заодно выкидывает запрещённые в fallback `%`,
     * `/`, `\` и пробелы). Если после чистки пусто — отдаём `download` с сохранённым
     * ASCII-расширением, чтобы имя всё же осталось осмысленным.
     */
    private static function asciiFallback(string $name): string
    {
        $ascii = $name;

        if (class_exists(\Transliterator::class)) {
            $tr = \Transliterator::create('Any-Latin; Latin-ASCII');
            if ($tr !== null) {
                $ascii = $tr->transliterate($name);
                if ($ascii === false) {
                    $ascii = $name;
                }
            }
        }

        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $ascii) ?? '';
        $ascii = trim($ascii, '_-.');

        if ($ascii === '' || $ascii === '.') {
            $ext   = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            $ext   = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';
            $ascii = $ext !== '' ? 'download.' . $ext : 'download';
        }

        return $ascii;
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
