<?php

declare(strict_types=1);

namespace aegis;

use aegis\alert\AlertService;
use aegis\event\DetectionEvent;
use aegis\network\Verdict;
use aegis\punishment\PunishmentService;
use aegis\session\SessionRegistry;

final readonly class DetectionService
{
	public function __construct(
		private SessionRegistry $sessions,
		private AlertService $alerts,
		private PunishmentService $punishments,
	) {}

	public function handle(Verdict $verdict): void
	{
		$session = $this->sessions->find($verdict->session);

		if ($session === null || !$session->player->isConnected() || $session->player->hasPermission('aegis.bypass')) {
			return;
		}

		$event = new DetectionEvent($session->player, $verdict->detection, $verdict->punish);
		$event->call();

		if ($event->isCancelled()) {
			return;
		}

		$this->alerts->notify($session, $verdict->detection, $verdict->punish);

		if ($verdict->punish) {
			$this->punishments->apply($session->player, $verdict->detection);
		}
	}
}
