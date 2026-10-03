<?php

declare(strict_types=1);

namespace aegis\network;

enum FrameType: int
{
	case Hello = 0x01;
	case Join = 0x02;
	case Quit = 0x03;
	case Serverbound = 0x04;
	case Clientbound = 0x05;
	case Tick = 0x06;
	case Observation = 0x07;
	case State = 0x08;
	case Input = 0x09;
	case Attack = 0x0a;
	case Swing = 0x0b;
	case Animation = 0x0c;

	case Welcome = 0x81;
	case Flag = 0x82;
	case Punish = 0x83;
}
