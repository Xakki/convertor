<?php

declare(strict_types=1);

namespace App\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\JWTAuthenticator;
use Symfony\Component\HttpFoundation\Request;

final class ApiJwtAuthenticator extends JWTAuthenticator
{
    public function supports(Request $request): ?bool
    {
        $authorization = $request->headers->get('Authorization', '');
        if (is_string($authorization) && preg_match('/^Bearer cnv_[A-Za-z0-9_-]{43}$/D', $authorization) === 1) {
            return false;
        }

        return parent::supports($request);
    }
}
