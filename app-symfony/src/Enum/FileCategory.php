<?php

declare(strict_types=1);

namespace App\Enum;

enum FileCategory: string
{
    case Document = 'document';
    case Image = 'image';
    case Audio = 'audio';
    case Video = 'video';
    case Data = 'data';
    case Archive = 'archive';
    case Markup = 'markup';
}
