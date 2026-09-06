<?php

declare(strict_types=1);

namespace App\DTO;

enum ApiAuditIdentityType: string
{
    case PERSONAL_TOKEN = 'personal_token';
    case JWT            = 'jwt';
    case GUEST          = 'guest';
    case WORKER         = 'worker';
    case INTERNAL       = 'internal';
}
