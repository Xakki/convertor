<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messenger;

use App\Message\ConversionMessage;
use App\Messenger\Transport\CleanRedisTransport;
use App\Service\Queue\RedisConnectionFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Golden на РЕАЛЬНОМ проводе: XADD через {@see CleanRedisTransport} в живой
 * KeyDB, чтение сырого поля `message` назад через XRANGE, байт-в-байт сверка с
 * замороженным fixture. В отличие от hermetic-unit'а этот тест ловит phpredis
 * PHP-сериализацию НА САМОМ ПРОВОДЕ (mock её воспроизвести не может, §9).
 *
 * Пишем в одноразовый стрим, чистим за собой — реальные `conv.*` очереди не
 * трогаем. Skips cleanly, когда KeyDB недоступен (CI без брокера).
 *
 * @group integration
 */
final class CleanRedisTransportKeyDbTest extends KernelTestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/messenger_envelope.golden.json';
    private const STREAM  = 'conv.__golden_test__';

    private RedisConnectionFactory $factory;
    private \Redis $redis;
    private SerializerInterface $serializer;

    protected function setUp(): void
    {
        self::bootKernel();

        $dsn = getenv('REDIS_DSN') ?: ($_SERVER['REDIS_DSN'] ?? 'redis://keydb:6379?dbindex=2');

        try {
            $this->factory = new RedisConnectionFactory((string) $dsn);
            $this->redis   = $this->factory->create();
            $this->redis->ping();
        } catch (\Throwable $e) {
            self::markTestSkipped('KeyDB not reachable: ' . $e->getMessage());
        }

        $this->redis->del(self::STREAM);

        /** @var SerializerInterface $serializer */
        $serializer       = self::getContainer()->get('messenger.transport.symfony_serializer');
        $this->serializer = $serializer;
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->del(self::STREAM);
        }
    }

    public function testXaddWritesCleanSingleJsonAtTheWire(): void
    {
        $transport = new CleanRedisTransport($this->factory, $this->serializer, self::STREAM);

        $transport->send(new Envelope(new ConversionMessage(
            conversionId: 123,
            inputBucket: 'convertor-inputs',
            inputKey: 'inputs/2026/06/19/ab12cd34.pdf',
            originalFilename: 'invoice.pdf',
            sourceFormat: 'pdf',
            targetFormat: 'docx',
            category: 'document',
            isAi: false,
            options: [],
        )));

        $entries = $this->redis->xRange(self::STREAM, '-', '+');
        self::assertIsArray($entries);
        self::assertCount(1, $entries, 'exactly one entry must be XADDed');

        $fields = reset($entries);
        self::assertIsArray($fields);
        self::assertArrayHasKey('message', $fields);

        // Байт-в-байт: сырое поле `message` == чистый single-JSON fixture.
        self::assertSame(file_get_contents(self::FIXTURE), $fields['message']);

        // Не PHP-сериализовано (нет `s:NNN:"...";`) и нет обёртки {body,headers}.
        $decoded = json_decode($fields['message'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('body', $decoded);
        self::assertSame(123, $decoded['conversionId']);
    }
}
