<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Сигнал «обнови кеш аватара пользователя из Telegram» (hardening-09/nit-2).
 * Раньше {@see \App\Service\Auth\TelegramAvatarService::refreshAvatar()} звался
 * СИНХРОННО прямо в обработке TG-webhook (findOrCreateUser): +3 HTTP-запроса к
 * Telegram API + PUT в S3 в ответ на webhook — риск таймаута/ретрая со стороны
 * Telegram. Теперь webhook только диспатчит это сообщение (транспорт `async`,
 * см. config/packages/messenger.yaml) и сразу отвечает — обновление аватара
 * происходит вне request-цикла в отдельном consumer'е (supervisor
 * `app-queue`, `messenger:consume async`).
 *
 * Несёт только `userId` (не всю сущность User — Messenger-сообщения должны
 * оставаться маленькими и сериализуемыми; хендлер сам перезагружает User по id).
 */
final class TelegramAvatarRefreshMessage
{
    public function __construct(
        public readonly int $userId,
    ) {
    }
}
