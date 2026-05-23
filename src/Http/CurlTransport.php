<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

use Infocyph\TalkingBytes\Core\Contract\TransportInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\Internal\CurlHandleConfigurator;
use Infocyph\TalkingBytes\Http\Internal\CurlResultFactory;
use Infocyph\TalkingBytes\Http\Internal\ResponseHeaderCollector;
use InvalidArgumentException;

final class CurlTransport implements TransportInterface
{
    public function send(CommunicationRequest $request): CommunicationResult
    {
        if (!$request->payload instanceof HttpRequest) {
            return CommunicationResult::failure('CurlTransport expects HttpRequest payload.');
        }

        return $this->sendRequest($request->payload);
    }

    public function sendRequest(HttpRequest $request): CommunicationResult
    {
        $resolvedRequest = $request->applyAuthenticators();
        $handle = curl_init();

        if ($handle === false) {
            return CommunicationResult::failure('Unable to initialize cURL handle.');
        }

        $headerCollector = new ResponseHeaderCollector();

        try {
            $configurator = new CurlHandleConfigurator();
            $resolvedRequest = $configurator->configure($handle, $resolvedRequest, $headerCollector);
        } catch (InvalidArgumentException $exception) {
            curl_close($handle);

            return CommunicationResult::failure($exception->getMessage());
        }

        $rawBody = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $info = curl_getinfo($handle);

        curl_close($handle);

        if (!is_string($rawBody)) {
            return CommunicationResult::failure(
                sprintf('cURL request failed (%d): %s', $errno, $error),
                metadata: ['curl' => is_array($info) ? $info : [], 'transport' => 'curl'],
            );
        }

        return CurlResultFactory::fromExecution(
            $resolvedRequest,
            'curl',
            $rawBody,
            $errno,
            $error,
            $info,
            $headerCollector->headers(),
        );
    }
}
