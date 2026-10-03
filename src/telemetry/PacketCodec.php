<?php

declare(strict_types=1);

namespace aegis\telemetry;

use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use ReflectionMethod;

final readonly class PacketCodec
{
	private ByteBufferWriter $writer;

	private bool $multiversion;

	public function __construct()
	{
		$this->writer = new ByteBufferWriter();
		$this->multiversion = (new ReflectionMethod(DataPacket::class, 'encode'))->getNumberOfRequiredParameters() > 1;
	}

	public function encode(DataPacket $packet): string
	{
		$this->writer->clear();
		$arguments = $this->multiversion ? [$this->writer, ProtocolInfo::CURRENT_PROTOCOL] : [$this->writer];
		$packet->encode(...$arguments);

		return $this->writer->getData();
	}

	public static function protocol(NetworkSession $session): int
	{
		if (method_exists($session, 'getProtocolId')) {
			$protocol = $session->getProtocolId();

			if (is_int($protocol)) {
				return $protocol;
			}
		}

		return ProtocolInfo::CURRENT_PROTOCOL;
	}
}
