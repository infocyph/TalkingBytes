<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Benchmarks;

use Infocyph\TalkingBytes\Http\HttpRequest;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;

#[BeforeMethods('setUp')]
final class HttpBench
{
    private HttpRequest $request;

    public function setUp(): void
    {
        $this->request = HttpRequest::post('https://api.example.com/v1/orders?existing=1#frag')
            ->header('X-App', 'TalkingBytes')
            ->header('X-Trace', 'bench-123')
            ->json([
                'order_id' => 1001,
                'customer' => [
                    'id' => 2002,
                    'segment' => 'enterprise',
                ],
                'lines' => [
                    ['sku' => 'sku-1', 'qty' => 2],
                    ['sku' => 'sku-2', 'qty' => 1],
                ],
            ])
            ->queries([
                'page' => 2,
                'include' => ['payments', 'shipments'],
                'active' => true,
            ])
            ->withBearerToken('token-123')
            ->withApiKeyHeader('X-Api-Key', 'key-456');
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchApplyAuthenticators(): void
    {
        $this->request->applyAuthenticators();
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchBuildUrl(): void
    {
        $this->request->buildUrl();
    }
}
