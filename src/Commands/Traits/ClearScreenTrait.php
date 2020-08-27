<?php

declare(strict_types=1);

namespace Nawarian\Juja\Commands\Traits;

use Symfony\Component\Console\{Cursor, Input\InputInterface, Output\OutputInterface, Style\SymfonyStyle, Terminal};
use Nawarian\Juja\Entities\Player\Player;

trait ClearScreenTrait
{
    private Player $player;

    private function clearScreen(InputInterface $input, OutputInterface $output): void
    {
        $cursor = new Cursor($output);
        $terminal = new Terminal();

        $cursor->clearScreen();

        $this->printPlayerState($input, $output);

        $mem = memory_get_usage(true) / 1024;
        $cursor->moveToPosition(
            $terminal->getWidth() - 15,
            $terminal->getHeight() - 4
        );
        $output->write("Mem: {$mem} KB");

        $cursor->moveToPosition(0, 0);
    }

    private function printPlayerState(InputInterface $input, OutputInterface $output): void
    {
        $cursor = new Cursor($output);
        $terminal = new Terminal();
        $style = new SymfonyStyle($input, $output);
        $totalRows = $terminal->getHeight();

        $cursor->moveToPosition(0, $totalRows);

        // Write player's status
        $cursor->moveToPosition(0, $totalRows);
        $style->success(
            "{$this->player->name} (Lv. {$this->player->level}) | HP: {$this->player->currentHP}/{$this->player->maxHP} | EXP: {$this->player->experience}",
        );
    }
}
