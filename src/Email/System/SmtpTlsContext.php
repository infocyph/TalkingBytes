<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

use Infocyph\TalkingBytes\Email\Config\SmtpConfig;

final readonly class SmtpTlsContext
{
    /** @return array<string, bool|int|string> */
    public function options(SmtpConfig $config): array
    {
        $options = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $config->host,
            'SNI_enabled' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ];
        foreach ([
            'cafile' => $config->caBundle,
            'local_cert' => $config->clientCertificate,
            'local_pk' => $config->clientKey,
            'passphrase' => $config->clientKeyPassphrase,
        ] as $key => $value) {
            if ($value !== null && $value !== '') {
                $options[$key] = $value;
            }
        }

        return $options;
    }
}
