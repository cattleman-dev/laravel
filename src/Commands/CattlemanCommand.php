<?php

namespace Cattleman\Laravel\Commands;

use Illuminate\Console\Command;

use function Laravel\Prompts\select;

/**
 * The palette: one menu for every Cattleman command. Sockets is the first
 * module; others add their commands to ACTIONS.
 */
class CattlemanCommand extends Command
{
    protected $signature = 'cattleman';

    protected $description = 'Set up and check Cattleman modules in this app';

    /** @var array<string, string> command => what it does */
    public const ACTIONS = [
        'cattleman:sockets' => 'Sockets: set up broadcasting through your Sockets worker',
        'cattleman:sockets:check' => 'Sockets: check the connection',
    ];

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->table(
                ['Command', 'What it does'],
                array_map(fn (string $command, string $label): array => [$command, $label], array_keys(self::ACTIONS), self::ACTIONS),
            );

            return self::SUCCESS;
        }

        $command = select(
            label: 'What would you like to do?',
            options: self::ACTIONS,
            scroll: 10,
        );

        return $this->call($command);
    }
}
