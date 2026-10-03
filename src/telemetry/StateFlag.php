<?php

declare(strict_types=1);

namespace aegis\telemetry;

enum StateFlag: int
{
	case Flying = 1;
	case Special = 2;
	case Ground = 4;
	case Lagging = 8;
	case Dead = 16;
	case Frozen = 32;
	case Ceiling = 64;
	case Physics = 128;
	case Obstructed = 256;
}
