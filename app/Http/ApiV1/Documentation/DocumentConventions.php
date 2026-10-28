<?php

namespace App\Http\ApiV1\Documentation;

use App\Enums\OAuthScope;
use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\SecuritySchemes\OAuthFlow;

/**
 * The document-wide part of the `/api/v1` spec: the two ways to authenticate
 * (a personal token, or an OAuth access token with `api:use`), either of
 * which every operation accepts.
 */
class DocumentConventions implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $document->components->addSecurityScheme('personalAccessToken', SecurityScheme::http('bearer')
            ->setDescription('A personal API token (`wm_api_…`) created in Settings → API. Read-only tokens carry `api:read`; read & write tokens also carry `api:write`.'));

        $document->components->addSecurityScheme('oauth2', SecurityScheme::oauth2()
            ->setDescription('An OAuth 2.1 access token (authorization code with PKCE) granted the `api:use` scope, which reads and writes. Clients register through dynamic client registration.')
            ->flow('authorizationCode', fn (OAuthFlow $flow): OAuthFlow => $flow
                ->authorizationUrl(route('passport.authorizations.authorize'))
                ->tokenUrl(route('passport.token'))
                ->refreshUrl(route('passport.token'))
                ->addScope(OAuthScope::Api->value, OAuthScope::Api->description())));

        $document->security = [
            new SecurityRequirement(['personalAccessToken' => []]),
            new SecurityRequirement(['oauth2' => [OAuthScope::Api->value]]),
        ];
    }
}
