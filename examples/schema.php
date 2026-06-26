<?php

declare(strict_types=1);

namespace Restate\Examples;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Structured input/output: handlers exchange JSON objects (here a product), plus a
 * scalar boolean. The runtime advertises each handler's JSON input/output in the
 * discovery manifest.
 *
 * Run:   php bin/restate-serve examples/schema.php
 * Try:   curl localhost:8080/CatalogService/getProductById -d '"p-1"'
 *        curl localhost:8080/CatalogService/saveProduct -d '{"id":"p-1","name":"Pen","priceCents":199}'
 *        curl localhost:8080/CatalogService/isInStock -d '"p-out-of-stock"'
 */
#[Service]
final class CatalogService
{
    /**
     * @return array{id: string, name: string, priceCents: int}
     */
    #[Handler]
    public function getProductById(Context $ctx, string $productId): array
    {
        $ctx->sleep(0.05);

        return ['id' => $productId, 'name' => 'Sample Product', 'priceCents' => 1995];
    }

    /**
     * @param array{id: string, name: string, priceCents: int} $product
     */
    #[Handler]
    public function saveProduct(Context $ctx, array $product): string
    {
        return $product['id'];
    }

    #[Handler]
    public function isInStock(Context $ctx, string $productId): bool
    {
        return !\str_contains($productId, 'out-of-stock');
    }
}

return Endpoint::builder()->bind(new CatalogService())->build();
