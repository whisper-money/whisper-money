<?php

namespace App\Enums;

/**
 * Where a user widget, or one of its revisions, was written: from the web app
 * or by the user's agent through MCP.
 */
enum WidgetOrigin: string
{
    case Web = 'web';
    case Mcp = 'mcp';
}
