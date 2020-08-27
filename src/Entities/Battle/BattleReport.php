<?php

declare(strict_types=1);

namespace Nawarian\Juja\Entities\Battle;

use DateTimeInterface;

final class BattleReport
{
    public int $battleId;

    public int $attackedId;

    public int $victimId;

    public int $winnerId;

    public DateTimeInterface $date;
}
