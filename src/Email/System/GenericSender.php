<?php

namespace Infocyph\TakingBytes\Email\System;

class GenericSender
{
    private array $status = ['sent' => false, 'error' => null];

    public function send(array $to, string $subject, string $message, string $headers)
    {
        mail(implode(',', $to), $subject, $message, $headers)
            ? $this->status['sent'] = true
            : $this->status['error'] = "Mail function failed.";

        return $this->status;
    }
}
