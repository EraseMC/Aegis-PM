<?php

declare(strict_types=1);

namespace aegis\telemetry;

enum ObservationType: int
{
	case Position = 0;
	case Remove = 1;
	case Teleport = 2;
	case Motion = 3;
	case Block = 4;
}
