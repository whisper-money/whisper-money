<?php

namespace App\Enums;

enum PropertyType: string
{
    case Residential = 'residential';
    case Commercial = 'commercial';
    case Land = 'land';
    case Vacation = 'vacation';
    case Other = 'other';
}
