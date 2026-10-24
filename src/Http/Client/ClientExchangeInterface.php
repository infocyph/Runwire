<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

/**
 * Transport-neutral streaming HTTP exchange.
 *
 * The operation returns at response-header completion. The returned body
 * remains streaming and is owned by the caller until consumed or closed.
 */
interface ClientExchangeInterface
{
    public function send(ClientRequest $request): ClientResponseInterface;
}
