<?php

// Event commands: make:listener and event:list. Loaded by console.php.

command('make:listener', 'Create a listener class [name] [--event=user.registered] [--queued]', function (array $args) {
    [$positional, $options] = parse_arguments($args);
    $name = str_studly(trim((string) ($positional[0] ?? '')));

    if ($name === '') {
        error_line('Usage: php console.php make:listener SendWelcomeMail --event=user.registered');

        return 1;
    }

    $event = trim((string) ($options['event'] ?? 'some.event'));
    $queued = isset($options['queued']) ? ' implements ShouldQueue' : '';

    $ok = write_stub(BASE_PATH . '/listeners/' . $name . '.php', <<<PHP
        <?php

        // Register it in config/events.php:
        //   '$event' => ['$name'],
        // Constructor dependencies are injected by the container. Implement
        // ShouldQueue (or set public bool \$shouldQueue = true) to run it on the
        // queue when the queue module is installed.

        class $name$queued
        {
            public function handle(...\$payload): void
            {
                //
            }
        }

        PHP);

    if ($ok) {
        line("Add to config/events.php: '$event' => ['$name'],");
    }

    return $ok ? 0 : 1;
});

command('event:list', 'Show the listeners registered at boot (config/events.php)', function () {
    $rows = [];

    foreach ($GLOBALS['__event_listeners'] as $event => $byPriority) {
        krsort($byPriority);

        foreach ($byPriority as $priority => $listeners) {
            foreach ($listeners as $listener) {
                $rows[] = [
                    $event,
                    is_string($listener) ? $listener : (is_array($listener) ? implode('@', array_map(fn ($p) => is_object($p) ? get_class($p) : $p, $listener)) : 'Closure'),
                    $priority,
                ];
            }
        }
    }

    if ($rows === []) {
        line('No listeners registered.');

        return 0;
    }

    print_table(['Event', 'Listener', 'Priority'], $rows);

    return 0;
});
