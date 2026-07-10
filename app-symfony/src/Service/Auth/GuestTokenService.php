<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Выпуск и проверка значения cookie `guest_id`.
 *
 * Формат значения: `<guestId>.<hmac>`, где
 *   - `guestId` — opaque URL-safe id (сырое значение, хранится в User.guestId);
 *   - `hmac`    — base64url(HMAC-SHA256(guestId, secret)).
 *
 * HMAC не даёт подобрать/подделать чужой guestId: без секрета нельзя собрать
 * валидную подпись. Секрет — APP_SECRET (переиспользуем, отдельная ротация не
 * требуется). Сравнение подписи — constant-time (hash_equals).
 */
final class GuestTokenService
{
    private const SEP = '.';

    public function __construct(
        private readonly string $secret,
    ) {
    }

    /**
     * Сгенерировать новый guestId (сырое значение для User.guestId).
     * 32 hex-символа = 128 бит энтропии, влезает в VARCHAR(64).
     */
    public function generateGuestId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Собрать подписанное значение cookie из сырого guestId.
     */
    public function sign(string $guestId): string
    {
        return $guestId . self::SEP . $this->hmac($guestId);
    }

    /**
     * Проверить подписанное значение cookie и вернуть сырой guestId.
     * Возвращает null при любой некорректности (нет разделителя, битая подпись).
     */
    public function verify(string $signed): ?string
    {
        $pos = strrpos($signed, self::SEP);
        if ($pos === false || $pos === 0) {
            return null;
        }

        $guestId = substr($signed, 0, $pos);
        $sig     = substr($signed, $pos + 1);

        if (! hash_equals($this->hmac($guestId), $sig)) {
            return null;
        }

        return $guestId;
    }

    private function hmac(string $guestId): string
    {
        $raw = hash_hmac('sha256', $guestId, $this->secret, true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
