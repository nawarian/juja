<?php

declare(strict_types=1);

namespace Nawarian\Juja\Entities\Player;

interface PlayerRepository
{
    public function store(Player $player): void;

    public function fetchById(int $playerId): Player;

    public function fetchByUrl(string $url): Player;

    public function fetchPlayersWeakerThan(Player $player, int $limit, int $offset): iterable;

    public function fetchPlayersWeakerAndWithHigherLevelThan(Player $player, int $limit, int $offset): iterable;
}
