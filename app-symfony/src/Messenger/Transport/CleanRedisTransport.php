<?php

declare(strict_types=1);

namespace App\Messenger\Transport;

use App\Service\Queue\RedisConnectionFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Кастомный Messenger-транспорт для job-стримов `conv.<type>` (Option D,
 * §3/§4 spec) — чистый single-JSON wire-контракт.
 *
 * Стоковый `symfony/redis-messenger` `Connection::add()` БЕЗУСЛОВНО оборачивает
 * payload в `json_encode(['body' => …, 'headers' => …])` и кладёт это в поле
 * стрима `message` → на чтении двойная декодировка. Обёртка структурна для
 * стокового транспорта, поэтому кастомный *serializer* (Option B) её убрать не
 * может — только кастомный *транспорт*.
 *
 * Здесь `send()` пишет `Serializer::encode($envelope)['body']` (тот самый чистый
 * camelCase JSON задачи, §3) НАПРЯМУЮ в поле `message` — без внешней обёртки.
 * Итог: одна декодировка вместо двух, и контракт стрима становится НАШИМ
 * (никто не читает эти стримы через Messenger — хендлеров нет,
 * `messenger:consume conv_*` запрещён; все читатели — сырой XREADGROUP).
 *
 * Транспорт produce-only: {@see get()}/{@see ack()}/{@see reject()} бросают
 * {@see LogicException} — Symfony НЕ должен джойнить consumer-группу воркеров.
 */
final class CleanRedisTransport implements TransportInterface, SetupableTransportInterface
{
    private bool $groupEnsured = false;

    public function __construct(
        private readonly RedisConnectionFactory $redisFactory,
        private readonly SerializerInterface $serializer,
        private readonly string $stream,
        private readonly string $group = 'convertor',
    ) {
    }

    public function send(Envelope $envelope): Envelope
    {
        // `body` = чистый single-JSON задачи (§3). `headers` намеренно
        // отбрасываются: наш контракт стрима их не несёт.
        $body = $this->serializer->encode($envelope)['body'];

        $redis = $this->redisFactory->create();

        // phpredis-footgun (§1/§4): без SERIALIZER_NONE phpredis PHP-сериализует
        // значение (`s:NNN:"{json}";`) и обернёт наш чистый JSON. Переносим
        // caveat стокового `serializer: 0`, а не устраняем его.
        $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);

        $this->ensureGroup($redis);

        $id = $redis->xAdd($this->stream, '*', ['message' => $body]);

        return $envelope->with(new TransportMessageIdStamp((string) $id));
    }

    public function setup(): void
    {
        $this->ensureGroup($this->redisFactory->create());
    }

    /**
     * @return iterable<Envelope>
     */
    public function get(): iterable
    {
        throw new LogicException(\sprintf('Transport "%s" is produce-only; PHP MUST NOT consume conv_* via Messenger (see docs/queue-contract.md §1). Stream readers use raw XREADGROUP.', $this->stream));
    }

    public function ack(Envelope $envelope): void
    {
        throw new LogicException(\sprintf('Transport "%s" is produce-only; ack is owned by the raw stream reader (see docs/queue-contract.md §1).', $this->stream));
    }

    public function reject(Envelope $envelope): void
    {
        throw new LogicException(\sprintf('Transport "%s" is produce-only; reject is owned by the raw stream reader (see docs/queue-contract.md §1).', $this->stream));
    }

    /**
     * XGROUP CREATE ... 0 MKSTREAM (идемпотентно; глотаем BUSYGROUP). Зеркалит
     * стоковый {@see Connection::setup()} — start-id `0`, чтобы группа видела
     * уже добавленные записи. (Чтение Stream/XGROUP теперь у WS-Gateway.)
     *
     * Один раз на экземпляр транспорта: гейт `$groupEnsured` убирает XGROUP-CREATE
     * round-trip (+ ловлю BUSYGROUP) на каждом `send()`.
     */
    private function ensureGroup(\Redis $redis): void
    {
        if ($this->groupEnsured) {
            return;
        }

        try {
            $redis->xGroup('CREATE', $this->stream, $this->group, '0', true);
        } catch (\RedisException $e) {
            if (! str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }

        $this->groupEnsured = true;
    }
}
