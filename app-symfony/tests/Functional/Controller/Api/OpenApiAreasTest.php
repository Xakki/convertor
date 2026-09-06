<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\Process;

final class OpenApiAreasTest extends WebTestCase
{
    private const EMAIL      = 'cnv86-openapi@example.test';
    private const USER_EMAIL = 'cnv86-openapi-user@example.test';

    private EntityManagerInterface $entityManager;


    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->entityManager->createQuery('DELETE FROM App\\Entity\\User user WHERE user.email IN (:emails)')
                ->setParameter('emails', [self::EMAIL, self::USER_EMAIL])
                ->execute();
            $this->entityManager->clear();
        }
        parent::tearDown();
    }

    public function testPublicAliasesAreAnonymousAndSpecsAreAudienceSeparated(): void
    {
        $client = static::createClient();

        foreach (['/api/doc', '/api/doc.json'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            self::assertStringContainsString('public', (string) $client->getResponse()->headers->get('cache-control'));
        }

        $client->request('GET', '/api/doc.json');
        $public = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('/api/v1/convert', $public['paths']);
        self::assertArrayNotHasKey('/api/v1/audit/history', $public['paths']);
        self::assertArrayNotHasKey('/api/v1/admin/ping', $public['paths']);
        self::assertArrayNotHasKey('/api/v1/worker/register', $public['paths']);
        self::assertArrayNotHasKey('/api/v1/internal/worker/result', $public['paths']);
        self::assertArrayNotHasKey('/api/v1/telegram/webhook', $public['paths']);
        self::assertArrayNotHasKey('/api/v1/convert/{id}/retry', $public['paths']);
        self::assertArrayNotHasKey('/api/v1/convert/{id}', $public['paths']);
        self::assertSame([], $public['paths']['/api/v1/examples']['get']['security']);
        self::assertSame([], $public['paths']['/api/v1/examples/file/{category}/{name}']['get']['security']);
        self::assertSame([], $public['paths']['/api/v1/examples/source/{category}/{name}']['get']['security']);
        self::assertSame([], $public['paths']['/api/v1/quota']['get']['security']);
        self::assertSame([], $public['paths']['/api/v1/convert']['post']['security']);
    }

    public function testLegacyDefaultDumpKeepsPriorNonWorkerSurface(): void
    {
        $process = new Process(['php', 'bin/console', 'nelmio:apidoc:dump', '--area=default', '--format=json', '--no-pretty']);
        $process->setTimeout(30);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $spec = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('/api/v1/convert/{id}/retry', $spec['paths']);
        self::assertArrayHasKey('/api/v1/admin/ping', $spec['paths']);
        self::assertArrayNotHasKey('/api/v1/worker/register', $spec['paths']);
        self::assertArrayNotHasKey('/api/v1/internal/worker/result', $spec['paths']);
    }

    public function testPrivateUserRequiresJwtAndContainsAuditButNotAdminSurfaces(): void
    {
        $client              = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $client->request('GET', '/api/user/doc.json');
        self::assertSame(401, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('cache-control'));

        $user = (new User())->setEmail(self::EMAIL)->setIsGuest(false);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        $client->request('GET', '/api/user/doc.json', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('cache-control'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('cache-control'));
        $userSpec = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('/api/v1/audit/history', $userSpec['paths']);
        self::assertSame([['PersonalToken' => []]], $userSpec['paths']['/api/v1/audit/history']['get']['security']);
        self::assertArrayNotHasKey('/api/v1/admin/ping', $userSpec['paths']);
        self::assertArrayNotHasKey('/api/v1/worker/register', $userSpec['paths']);
        self::assertArrayHasKey('/api/v1/convert/{id}/retry', $userSpec['paths']);
        self::assertSame([['Bearer' => []]], $userSpec['paths']['/api/v1/convert/{id}/retry']['post']['security']);
    }

    public function testPrivateAdminRequiresAdminAndContainsServiceSurfaces(): void
    {
        $client              = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $client->request('GET', '/api/admin/doc.json');
        self::assertSame(401, $client->getResponse()->getStatusCode());

        $user = (new User())->setEmail(self::EMAIL)->setIsGuest(false)->setIsAdmin(true);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $client->request('GET', '/api/admin/doc.json', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('cache-control'));
        $adminSpec = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('/api/v1/admin/ping', $adminSpec['paths']);
        self::assertArrayHasKey('/api/v1/worker/register', $adminSpec['paths']);
        self::assertArrayHasKey('/api/v1/internal/worker/result', $adminSpec['paths']);
        self::assertArrayHasKey('/api/v1/telegram/webhook', $adminSpec['paths']);
        self::assertArrayNotHasKey('/api/v1/audit/history', $adminSpec['paths']);

        $client->request('GET', '/api/user/doc.json', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);
        self::assertResponseIsSuccessful();
    }

    public function testPrivateAdminRejectsRegularUser(): void
    {
        $client              = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user                = (new User())->setEmail(self::USER_EMAIL)->setIsGuest(false);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        $client->request('GET', '/api/admin/doc.json', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }
}
