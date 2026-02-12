<?php

namespace VirLatinus\Auditing\Drivers;

enum Rotation: string
{
    case Single = 'single';
    case Hourly = 'hourly';
    case Daily = 'daily';
}
