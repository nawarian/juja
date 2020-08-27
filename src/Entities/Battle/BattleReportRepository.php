<?php

declare(strict_types=1);

namespace Nawarian\Juja\Entities\Battle;

interface BattleReportRepository
{
    public function store(BattleReport $battleReport): void;

    public function findByAttackerIdAndVictimId(int $attackerId, int $victimId): iterable;
}
