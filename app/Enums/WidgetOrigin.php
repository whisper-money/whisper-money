<?php

namespace App\Enums;

/**
 * Where a user widget, one of its revisions or a dashboard was written: from the web app
 * or by the user's agent through MCP.
 */
enum WidgetOrigin: string
{
    case Web = 'web';
    case Mcp = 'mcp';
}
