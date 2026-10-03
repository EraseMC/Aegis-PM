<?php

declare(strict_types=1);

namespace aegis\network;

use Closure;
use pocketmine\scheduler\AsyncTask;

final class ResolveHostTask extends AsyncTask
{
	public function __construct(private string $host, Closure $callback)
	{
		$this->storeLocal('callback', $callback);
	}

	public function onRun(): void
	{
		$this->setResult(gethostbyname($this->host));
	}

	public function onCompletion(): void
	{
		$callback = $this->fetchLocal('callback');
		$address = $this->getResult();

		if ($callback instanceof Closure) {
			$callback(is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false ? $address : null);
		}
	}
}
