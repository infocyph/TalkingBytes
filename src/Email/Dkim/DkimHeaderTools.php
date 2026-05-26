<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Dkim;

final class DkimHeaderTools
{
    /**
     * @return list<string>
     */
    public static function unfoldLines(string $headers): array
    {
        $lines = preg_split('/\r\n/', $headers) ?: [];
        $unfolded = [];

        foreach ($lines as $line) {
            if (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
                $last = array_key_last($unfolded);
                if ($last !== null) {
                    $unfolded[$last] .= ' ' . ltrim($line);
                }

                continue;
            }

            $unfolded[] = $line;
        }

        return $unfolded;
    }
}
