<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Template;

final readonly class ArrayVariableRenderer implements TemplateRendererInterface
{
    /**
     * @param array<string, scalar|\Stringable|null> $variables
     */
    public function render(string $template, array $variables): string
    {
        $replace = [];

        foreach ($variables as $key => $value) {
            $replace['{{' . $key . '}}'] = $value === null ? '' : (string) $value;
        }

        return strtr($template, $replace);
    }
}
