<?php

namespace Osiset\BasicShopifyAPI\Clients;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Osiset\BasicShopifyAPI\Contracts\GraphRequester;
use Psr\Http\Message\ResponseInterface;

/**
 * GraphQL client.
 */
class Graph extends AbstractClient implements GraphRequester
{
    /**
     * {@inheritdoc}
     *
     * @throws Exception When missing api password is missing for private apps.
     * @throws Exception When missing access key is missing for public apps.
     */
    public function request(string $query, array $variables = [], bool $sync = true)
    {
        /**
         * Run the request as sync or async.
         */
        $requestFn = function (array $request) use ($sync) {
            // Encode the request
            $json = json_encode($request);

            // Run the request
            $fn = $sync ? 'request' : 'requestAsync';

            return $this->getClient()->{$fn}(
                'POST',
                $this->getBaseUri()->withPath('/admin/api/graphql.json'),
                ['body' => $json]
            );
        };

        // Build the request
        $request = ['query' => $query];
        if (count($variables) > 0) {
            $request['variables'] = $variables;
        }

        if ($sync === false) {
            // Async request
            $promise = $requestFn($request);

            return $promise->then([$this, 'handleSuccess'], [$this, 'handleFailure']);
        }

        // Sync request (default)
        try {
            $response = $requestFn($request);

            return $this->handleSuccess($response);
        } catch (RequestException $e) {
            return $this->handleFailure($e);
        }
    }

    /**
     * Handle response from request.
     *
     * @param ResponseInterface $resp
     *
     * @return array
     */
    public function handleSuccess(ResponseInterface $resp): array
    {
        // Convert data to response
        $body = $this->toResponse($resp->getBody());

        // Return Guzzle response and JSON-decoded body
        return [
            'errors'     => $body->hasErrors() ? $body->getErrors() : false,
            'response'   => $resp,
            'status'     => $resp->getStatusCode(),
            'body'       => $body,
            'timestamps' => $this->getTimeStore()->get($this->getSession()),
        ];
    }

    /**
     * Handle failure of response.
     *
     * @param RequestException $e
     *
     * @return array
     */
    public function handleFailure(RequestException $e): array
    {
        report($e);

        $resp = $e->getResponse();
        $body = null;
        $status = null;

        if ($resp) {
            // Get the body stream
            $rawBody = $resp->getBody();
            $status = $resp->getStatusCode();

            // Build the error object
            if ($rawBody !== null) {
                // Convert data to response
                $body = $this->toResponse($rawBody);
                $body = $body->hasErrors() ? $body->getErrors() : null;
            }

            $logContext['shopify_response'] = [
                'status_code' => $resp->getStatusCode(),
                'body' => $body,   // $resp->getBody()->__toString(),
                'headers' => $resp->getHeaders()
            ];

        }

        $request = $e->getRequest();

        $logContext['shopify_request'] = [
            'api_type' => 'GraphQL',
            'uri' => $request->getUri()->__toString(),
            'method' => $request->getMethod(),
            'headers' => $request->getHeaders(),
            'body' => $request->getBody()->__toString()
        ];

        Log::error('Shopify API Requset failed', $logContext);

        return [
            'errors'     => true,
            'response'   => $resp,
            'status'     => $status,
            'body'       => $body,
            'exception'  => $e,
            'timestamps' => $this->getTimeStore()->get($this->getSession()),
        ];
    }
}
