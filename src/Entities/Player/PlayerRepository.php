<?php

declare(strict_types=1);

namespace Nawarian\KFStats\Entities\Player;

interface PlayerRepository
{
    public function store(Player $player): void;

    public function fetchById(int $playerId): Player;

    public function fetchPlayersWeakerThan(Player $player, int $limit, int $offset): iterable;
}
