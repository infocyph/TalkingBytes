<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

final class LineEndingNormalizer
{
    private bool $pendingCarriageReturn = false;

    public function finish(): string
    {
        if (!$this->pendingCarriageReturn) {
            return '';
        }

        $this->pendingCarriageReturn = false;

        return "\r\n";
    }

    public function push(string $chunk): string
    {
        if ($chunk === '') {
            return '';
        }

        $prefix = '';
        if ($this->pendingCarriageReturn) {
            $prefix = "\r\n";
            if (str_starts_with($chunk, "\n")) {
                $chunk = substr($chunk, 1);
            }
            $this->pendingCarriageReturn = false;
        }

        if (str_ends_with($chunk, "\r")) {
            $chunk = substr($chunk, 0, -1);
            $this->pendingCarriageReturn = true;
        }

        return $prefix . str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $chunk));
    }
}
