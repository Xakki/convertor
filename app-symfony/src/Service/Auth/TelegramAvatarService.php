<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Service\Storage\S3Storage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Фетчит аватар Telegram-профиля и кеширует его в S3 (results-бакет,
 * ключ `avatars/{userId}.{ext}`), сохраняя в `User.photoUrl` НАШУ ссылку
 * (S3-ключ), а не сырой getFile-URL (тот несёт bot-токен и протухает).
 *
 * Best-effort: любой сбой (нет фото, ошибка TG API/S3) — не фатален, логируется
 * и НЕ роняет логин. Стратегия обновления v1: рефетч на каждом bot-логине
 * (логин редкий, вебхук серверный — не блокирует UI пользователя).
 *
 * Не final — функциональные тесты подменяют его в контейнере через createMock.
 */
class TelegramAvatarService
{
    /** Разумный предел ширины кешируемого аватара (пикс.): берём наибольший ≤ этого. */
    private const MAX_WIDTH = 320;

    public function __construct(
        private readonly TelegramBotClient $botClient,
        private readonly S3Storage $s3,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Обновляет аватар пользователя (best-effort). Требует telegramId и id
     * (персистнутый User). Ничего не бросает наружу — при любой ошибке логирует
     * и возвращает управление, оставляя photoUrl как есть.
     */
    public function refreshAvatar(User $user): void
    {
        $telegramId = $user->getTelegramId();
        $userId     = $user->getId();
        if ($telegramId === null || $userId === null) {
            return;
        }

        try {
            $size = $this->pickPhotoSize($this->botClient->getUserProfilePhotos($telegramId));
            if ($size === null) {
                // У пользователя нет фото профиля — это норма, не ошибка.
                return;
            }

            $fileId   = is_string($size['file_id'] ?? null) ? $size['file_id'] : '';
            $filePath = $this->extractFilePath($this->botClient->getFile($fileId));
            if ($fileId === '' || $filePath === null) {
                return;
            }

            $bytes = $this->botClient->downloadFile($filePath);
            if ($bytes === null || $bytes === '') {
                return;
            }

            $ext = $this->extFromPath($filePath);
            $key = 'avatars/' . $userId . '.' . $ext;
            $this->s3->putObject($this->s3->resultsBucket(), $key, $bytes, self::mimeForExt($ext));

            $user->setPhotoUrl($key);
            $this->em->flush();
        } catch (\Throwable $e) {
            // Никогда не блокируем логин из-за аватара.
            $this->logger->warning('Telegram avatar fetch failed', [
                'userId' => $userId,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Выбирает «разумно-маленький» размер: наибольший из ≤ MAX_WIDTH, иначе —
     * самый маленький. Ждём result.photos[0] = массив PhotoSize одного фото.
     *
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>|null
     */
    private function pickPhotoSize(array $response): ?array
    {
        $result = is_array($response['result'] ?? null) ? $response['result'] : [];
        $photos = is_array($result['photos'] ?? null) ? $result['photos'] : [];
        $first  = is_array($photos[0] ?? null) ? $photos[0] : [];

        /** @var list<array<string, mixed>> $sizes */
        $sizes = array_values(array_filter($first, 'is_array'));
        if ($sizes === []) {
            return null;
        }

        usort($sizes, static fn (array $a, array $b): int => (int) ($a['width'] ?? 0) <=> (int) ($b['width'] ?? 0));

        $chosen = $sizes[0];
        foreach ($sizes as $size) {
            if ((int) ($size['width'] ?? 0) <= self::MAX_WIDTH) {
                $chosen = $size;
            }
        }

        return $chosen;
    }

    /**
     * @param array<string, mixed> $getFileResponse
     */
    private function extractFilePath(array $getFileResponse): ?string
    {
        $result = is_array($getFileResponse['result'] ?? null) ? $getFileResponse['result'] : [];
        $path   = $result['file_path'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function extFromPath(string $filePath): string
    {
        $ext = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';

        return $ext !== '' ? $ext : 'jpg';
    }

    public static function mimeForExt(string $ext): string
    {
        return match (strtolower($ext)) {
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            'jpg', 'jpeg' => 'image/jpeg',
            default       => 'image/jpeg',
        };
    }
}
