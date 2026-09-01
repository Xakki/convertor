<?php

declare(strict_types=1);

namespace App\Messenger\Transport;

use App\Service\Queue\RedisConnectionFactory;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Factory для {@see CleanRedisTransport} (Option D, §3 spec).
 *
 * Привязывается к транспортам `conv_*` через кастомную DSN-схему `conv+redis://`
 * (в `messenger.yaml`: `dsn: 'conv+%env(REDIS_DSN)%'`). Схема с префиксом
 * `conv+` не пересекается с `supports()` стокового `RedisTransportFactory`
 * (`redis:`/`rediss:`/`valkey:`), поэтому `failed` (`redis://…`) по-прежнему
 * едет на сток, а только 8 `conv_*` — на наш транспорт.
 *
 * Автоконфигурится тегом `messenger.transport_factory` (FrameworkBundle тегает
 * все {@see TransportFactoryInterface}). Соединение берём из общего
 * {@see RedisConnectionFactory} (тот же `REDIS_DSN`, db из `dbindex`).
 *
 * @implements TransportFactoryInterface<CleanRedisTransport>
 */
final class CleanRedisTransportFactory implements TransportFactoryInterface
{
    private const PREFIX = 'conv+';

    public function __construct(
        private readonly RedisConnectionFactory $redisFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $stream = $options['stream'] ?? null;
        if (! is_string($stream) || $stream === '') {
            throw new InvalidArgumentException('The "stream" option is required for a conv+redis transport.');
        }

        $group = $options['group'] ?? 'convertor';

        return new CleanRedisTransport($this->redisFactory, $serializer, $stream, (string) $group);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, self::PREFIX . 'redis://')
            || str_starts_with($dsn, self::PREFIX . 'rediss://')
            || str_starts_with($dsn, self::PREFIX . 'valkey://')
            || str_starts_with($dsn, self::PREFIX . 'valkeys://');
    }
}
