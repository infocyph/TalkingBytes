<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Template;

interface TemplateRendererInterface
{
    /**
     * @param array<string, scalar|\Stringable|null> $variables
     */
    public function render(string $template, array $variables): string;
}
