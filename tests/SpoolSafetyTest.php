<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Event\CallableEventDispatcher;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Parser\EmailParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail;

function spoolSafetyMessage(string $subject): string
{
    return "From: sender@example.test\r\nTo: recipient@example.test\r\nSubject: {$subject}\r\n\r\nPayload";
}

function spoolSafetyRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            spoolSafetyRemove($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

it('does not overwrite a freshly arrived spool file while restoring a claimed message', function (): void {
    $root = sys_get_temp_dir().'/tb-restore-'.bin2hex(random_bytes(6));
    mkdir($root, 0700);
    $first = spoolSafetyMessage('original');
    $replacement = spoolSafetyMessage('new arrival');
    file_put_contents($root.'/queued.eml', $first);

    $parser = new class($root, $replacement) implements EmailParser {
        public function __construct(private string $root, private string $replacement) {}

        public function parse(string $rawEmail, array $metadata = []): ParsedEmail
        {
            file_put_contents($this->root.'/queued.eml', $this->replacement);

            return (new RawEmailParser())->parse($rawEmail, $metadata);
        }
    };

    try {
        expect(fn() => (new SpoolEmailReceiver(new SpoolConfig($root), parser: $parser))->receiveParsed())
            ->toThrow(RuntimeException::class, 'without overwriting');
        expect(file_get_contents($root.'/queued.eml'))->toBe($replacement);
        $claimed = glob($root.'/.*.processing') ?: [];
        expect($claimed)->toHaveCount(1)
            ->and(file_get_contents($claimed[0]))->toBe($first);
    } finally {
        spoolSafetyRemove($root);
    }
});

it('does not read a swapped-in symlink between spool discovery and file admission', function (): void {
    $root = sys_get_temp_dir().'/tb-path-swap-'.bin2hex(random_bytes(6));
    mkdir($root, 0700);
    $source = $root.'/inbox';
    mkdir($source, 0700);
    $original = spoolSafetyMessage('original');
    $external = spoolSafetyMessage('external secret');
    file_put_contents($source.'/queued.eml', $original);
    file_put_contents($root.'/external.eml', $external);

    $events = new CallableEventDispatcher(static function (string $event) use ($root, $source): void {
        if ($event !== 'email.receive.start') {
            return;
        }

        rename($source.'/queued.eml', $root.'/preserved.eml');
        symlink($root.'/external.eml', $source.'/queued.eml');
    });

    try {
        $result = (new SpoolEmailReceiver(
            new SpoolConfig($source),
            deleteAfterRead: true,
            events: $events,
        ))->receiveParsed();

        expect($result)->toBeNull()
            ->and(file_get_contents($root.'/external.eml'))->toBe($external)
            ->and(file_get_contents($root.'/preserved.eml'))->toBe($original);
    } finally {
        spoolSafetyRemove($root);
    }
});

it('publishes spool messages across filesystems without clobbering an existing destination', function (): void {
    $sourceRoot = sys_get_temp_dir().'/tb-cross-source-'.bin2hex(random_bytes(6));
    $destinationRoot = '/dev/shm/tb-cross-destination-'.bin2hex(random_bytes(6));
    if (!is_dir('/dev/shm') || !is_writable('/dev/shm')) {
        return;
    }

    mkdir($sourceRoot, 0700);
    mkdir($destinationRoot, 0700);
    try {
        $sourceState = stat($sourceRoot);
        $destinationState = stat($destinationRoot);
        if (!is_array($sourceState) || !is_array($destinationState)
            || $sourceState['dev'] === $destinationState['dev']) {
            return;
        }

        $first = spoolSafetyMessage('cross filesystem');
        $existing = spoolSafetyMessage('already published');
        file_put_contents($sourceRoot.'/queued.eml', $first);
        file_put_contents($destinationRoot.'/queued.eml', $existing);

        $received = (new SpoolEmailReceiver(
            new SpoolConfig($sourceRoot),
            moveAfterRead: $destinationRoot,
        ))->receiveParsed();

        $files = glob($destinationRoot.'/*.eml') ?: [];
        expect($received?->subject)->toBe('cross filesystem')
            ->and(file_get_contents($destinationRoot.'/queued.eml'))->toBe($existing)
            ->and($files)->toHaveCount(2)
            ->and(file_exists($sourceRoot.'/queued.eml'))->toBeFalse();

        $published = array_values(array_filter($files, static fn(string $path): bool => basename($path) !== 'queued.eml'));
        expect($published)->toHaveCount(1)
            ->and(file_get_contents($published[0]))->toBe($first);
    } finally {
        spoolSafetyRemove($sourceRoot);
        spoolSafetyRemove($destinationRoot);
    }
});

it('claims a spool file for only one process and preserves a crashed worker claim', function (): void {
    $root = sys_get_temp_dir().'/tb-workers-'.bin2hex(random_bytes(6));
    $source = $root.'/inbox';
    $processing = $root.'/processing';
    mkdir($source, 0700, true);
    mkdir($processing, 0700);
    $fixture = __DIR__.'/Fixtures/spool-consumer.php';

    try {
        foreach (['hold', 'crash'] as $mode) {
            file_put_contents($source.'/queued.eml', spoolSafetyMessage($mode));
            $marker = $root.'/'.$mode.'.marker';
            $a = $root.'/'.$mode.'-first.json';
            $b = $root.'/'.$mode.'-second.json';
            $spawn = static function (string $action, string $output) use ($fixture, $source, $processing, $marker): mixed {
                $process = proc_open(
                    [PHP_BINARY, $fixture, $source, $action, $action === 'consume' ? '' : $marker, $output, $processing],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                if (!is_resource($process)) {
                    throw new RuntimeException('Unable to start spool regression worker.');
                }
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }

                return $process;
            };

            $first = $spawn($mode, $a);
            $deadline = microtime(true) + 5.0;
            while (!is_file($marker) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            expect(is_file($marker))->toBeTrue();

            $second = $spawn('consume', $b);
            expect(proc_close($second))->toBe(0);
            expect(json_decode((string) file_get_contents($b), true, 512, JSON_THROW_ON_ERROR)['subject'])->toBeNull();
            expect(proc_close($first))->toBe(0);

            if ($mode === 'hold') {
                expect(json_decode((string) file_get_contents($a), true, 512, JSON_THROW_ON_ERROR)['subject'])->toBe('hold');
                expect(glob($processing.'/*.eml') ?: [])->toHaveCount(0);
            } else {
                expect(glob($processing.'/*.eml') ?: [])->toHaveCount(1);
            }

            foreach (glob($processing.'/*.eml') ?: [] as $path) {
                unlink($path);
            }
        }
    } finally {
        spoolSafetyRemove($root);
    }
});
