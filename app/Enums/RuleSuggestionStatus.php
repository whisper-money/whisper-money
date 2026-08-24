<?php

namespace App\Enums;

enum RuleSuggestionStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Dismissed = 'dismissed';
}
