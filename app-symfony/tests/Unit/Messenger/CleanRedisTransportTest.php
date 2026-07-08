<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

use App\Message\ConversionMessage;
use App\Messenger\Transport\CleanRedisTransport;
use App\Service\Queue\RedisConnectionFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Golden-страж чистого single-JSON wire-контракта (Option D, §3/§4/§9 spec).
 *
 * Гоняем фиксированное {@see ConversionMessage} через {@see CleanRedisTransport}
 * с реальным Messenger-сериализатором и mock-\Redis, перехватывающим XADD.
 * Ассертим ОБА инварианта, которые обязан ловить golden (§9):
 *  1. байты поля стрима `message` == замороженный fixture (нет внешней обёртки
 *     `{body,headers}` стокового redis-messenger);
 *  2. writer выставил `\Redis::SERIALIZER_NONE` ДО `XADD` — иначе phpredis
 *     PHP-сериализует наш чистый JSON (footgun §1/§4).
 */
final class CleanRedisTransportTest extends KernelTestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/messenger_envelope.golden.json';

    private SerializerInterface $serializer;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var SerializerInterface $serializer */
        $serializer       = self::getContainer()->get('messenger.transport.symfony_serializer');
        $this->serializer = $serializer;
    }

    public function testSendWritesCleanSingleJsonWithSerializerNone(): void
    {
        $serializerOptionCalls = [];
        $captured              = null;

        $redis = $this->createStub(\Redis::class);
        $redis->method('setOption')->willReturnCallback(
            function (int $opt, mixed $val) use (&$serializerOptionCalls): bool {
                if ($opt === \Redis::OPT_SERIALIZER) {
                    $serializerOptionCalls[] = $val;
                }

                return true;
            },
        );
        $redis->method('xGroup')->willReturn(true);
        $redis->method('xAdd')->willReturnCallback(
            function (string $stream, string $id, array $fields) use (&$captured): string {
                $captured = ['stream' => $stream, 'fields' => $fields];

                return '1717000000000-0';
            },
        );

        $transport = new CleanRedisTransport(
            $this->fakeFactory($redis),
            $this->serializer,
            'conv.__golden__',
        );

        $transport->send(new Envelope($this->fixedMessage()));

        self::assertNotNull($captured, 'XADD was not invoked');
        self::assertSame('conv.__golden__', $captured['stream']);
        self::assertArrayHasKey('message', $captured['fields']);

        $message = $captured['fields']['message'];

        // Инвариант 1 — байт-в-байт чистый single-JSON (без {body,headers}).
        self::assertSame(
            file_get_contents(self::FIXTURE),
            $message,
            'stream `message` field must equal the frozen golden (clean single-JSON, no envelope wrap)',
        );

        // Никакой внешней обёртки: декодированное — это САМА задача, а не {body,headers}.
        $decoded = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('body', $decoded);
        self::assertArrayNotHasKey('headers', $decoded);
        self::assertSame(123, $decoded['conversionId']);

        // Инвариант 2 — SERIALIZER_NONE выставлен (перед XADD), phpredis не обернёт JSON.
        self::assertContains(
            \Redis::SERIALIZER_NONE,
            $serializerOptionCalls,
            'writer MUST set \Redis::SERIALIZER_NONE before XADD (phpredis footgun §1/§4)',
        );
    }

    private function fixedMessage(): ConversionMessage
    {
        return new ConversionMessage(
            conversionId: 123,
            inputBucket: 'convertor-inputs',
            inputKey: 'inputs/2026/06/19/ab12cd34.pdf',
            originalFilename: 'invoice.pdf',
            sourceFormat: 'pdf',
            targetFormat: 'docx',
            category: 'document',
            isAi: false,
            options: [],
        );
    }

    private function fakeFactory(\Redis $redis): RedisConnectionFactory
    {
        return new class ($redis) extends RedisConnectionFactory {
            public function __construct(private readonly \Redis $mock)
            {
                parent::__construct('redis://127.0.0.1:6379');
            }

            public function create(): \Redis
            {
                return $this->mock;
            }
        };
    }
}
