<?php

namespace App\Enums;

/**
 * The user's answer to "¿Fue puntual o se repite?" about a spike.
 */
enum ForecastSpikeAnswer: string
{
    case OneOff = 'one_off';
    case Repeats = 'repeats';
}
