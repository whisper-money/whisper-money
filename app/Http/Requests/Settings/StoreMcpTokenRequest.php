<?php

namespace App\Http\Requests\Settings;

class StoreMcpTokenRequest extends StoreAccessTokenRequest
{
    /**
     * Any plan may create an MCP token: the plan is checked when the token is
     * used, by the MCP tools.
     */
    public function authorize(): bool
    {
        return true;
    }
}
