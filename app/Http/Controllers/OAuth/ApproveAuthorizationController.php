<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\OAuth\Concerns\GuardsOAuthScopes;
use Illuminate\Http\Request;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController as PassportApproveAuthorizationController;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passport's `POST /oauth/authorize`, bound in its place. The consent screen
 * hides the approve button from a user who may not approve the request; this
 * refuses the approval itself, so posting the form by hand gets the client an
 * `access_denied` instead of a code.
 *
 * The body repeats Passport's approve() rather than calling it: reading the
 * request pulls it out of the session, so the parent could not read it again.
 * Keep it in step with Passport on upgrades.
 */
class ApproveAuthorizationController extends PassportApproveAuthorizationController
{
    use GuardsOAuthScopes;

    public function approve(Request $request, ResponseInterface $psrResponse): Response
    {
        $authRequest = $this->getAuthRequestFromSession($request);

        $this->ensureUserMayApprove($authRequest, $request->user());

        $authRequest->setAuthorizationApproved(true);

        return $this->withErrorHandling(fn () => $this->convertResponse(
            $this->server->completeAuthorizationRequest($authRequest, $psrResponse)
        ), $authRequest->getGrantTypeId() === 'implicit');
    }
}
