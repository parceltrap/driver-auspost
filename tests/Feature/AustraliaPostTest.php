<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use ParcelTrap\AusPost\AusPost;
use ParcelTrap\Contracts\Factory;
use ParcelTrap\DTOs\TrackingDetails;
use ParcelTrap\Enums\Status;
use ParcelTrap\Exceptions\ApiAuthenticationFailedException;
use ParcelTrap\Exceptions\ApiLimitReachedException;
use ParcelTrap\ParcelTrap;

function getMockAusPost($app, array $trackingDetails): void
{
    $httpMockHandler = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode($trackingDetails)),
    ]);

    $handlerStack = HandlerStack::create($httpMockHandler);

    $httpClient = new Client([
        'handler' => $handlerStack,
    ]);

    $app->make(Factory::class)->extend(AusPost::IDENTIFIER, fn () => new AusPost(
        apiKey: 'abcdefg',
        password: 'test',
        accountNumber: 'abc123',
        client: $httpClient,
    ));
}

it('can add the AusPost driver to ParcelTrap', function () {
    /** @var ParcelTrap $client */
    $client = $this->app->make(Factory::class);

    $client->extend('auspost_other', fn () => new AusPost(
        apiKey: 'abcdefg',
        password: 'test',
        accountNumber: 'abc123',
    ));

    expect($client)->driver(AusPost::IDENTIFIER)->toBeInstanceOf(AusPost::class)
        ->and($client)->driver('auspost_other')->toBeInstanceOf(AusPost::class);
});

it('can retrieve the AusPost driver from ParcelTrap', function () {
    expect($this->app->make(Factory::class)->driver(AusPost::IDENTIFIER))->toBeInstanceOf(AusPost::class);
});

it('can call `find` on the AusPost driver and handle invalid tracking ID response', function () {
    $trackingDetails = [
        'tracking_results' => [
            [
                'tracking_id' => '7XX1000',
                'errors' => [
                    [
                        'code' => 'ESB-10001',
                        'name' => 'Invalid tracking ID',
                    ],
                ],
            ],
        ],
    ];

    getMockAusPost($this->app, $trackingDetails);

    expect($this->app->make(Factory::class)->driver(AusPost::IDENTIFIER)->find('7XX1000'))
        ->toBeInstanceOf(TrackingDetails::class)
        ->identifier->toBe('7XX1000')
        ->status->toEqual(Status::Not_Found)
        ->status->description()->toBe('Not Found')
        ->summary->toBe('Invalid Tracking ID: The requested consignment could not be found.')
        ->estimatedDelivery->toBeNull()
        ->raw->toBe($trackingDetails);
});

it('can call `find` on the AusPost driver and handle a successful response', function () {
    $trackingDetails = [
        'tracking_results' => [
            [
                'tracking_id' => '7XX1000634011427',
                'status' => 'Delivered',
                'trackable_items' => [
                    [
                        'article_id' => '7XX1000634011427',
                        'product_type' => 'eParcel',
                        'events' => [
                            [
                                'location' => 'ALEXANDRIA NSW',
                                'description' => 'Delivered',
                                'date' => '2014-05-30T14:43:09+10:00',
                            ],
                            [
                                'location' => 'ALEXANDRIA NSW',
                                'description' => 'With Australia Post for delivery today',
                                'date' => '2014-05-30T06:08:51+10:00',
                            ],
                            [
                                'location' => 'CHULLORA NSW',
                                'description' => 'Processed through Australia Post facility',
                                'date' => '2014-05-29T19:40:19+10:00',
                            ],
                            [
                                'location' => 'SYDNEY (AU)',
                                'description' => 'Arrived at facility in destination country',
                                'date' => '2014-05-29T10:16:00+10:00',
                            ],
                            [
                                'location' => 'JOHN F. KENNEDY APT\/NEW YORK (US)',
                                'description' => 'Departed facility',
                                'date' => '2014-05-26T05:00:00+10:00',
                            ],
                            [
                                'location' => 'JOHN F. KENNEDY APT\/NEW YORK (US)',
                                'description' => 'Departed facility',
                                'date' => '2014-05-26T05:00:00+10:00',
                            ],
                            [
                                'description' => 'Shipping information approved by Australia Post',
                                'date' => '2014-05-23T14:27:15+10:00',
                            ],
                        ],
                        'status' => 'Delivered',
                    ],
                ],
            ],
        ],
    ];

    getMockAusPost($this->app, $trackingDetails);

    expect($this->app->make(Factory::class)->driver(AusPost::IDENTIFIER)->find('7XX1000634011427'))
        ->toBeInstanceOf(TrackingDetails::class)
        ->identifier->toBe('7XX1000634011427')
        ->status->toEqual(Status::Delivered)
        ->status->description()->toBe('Delivered')
        ->summary->toBe('The item or items in the shipment have been delivered.')
        ->estimatedDelivery->toBeNull()
        ->raw->toBe($trackingDetails);
});

it('can call `find` on the AusPost driver and handle a consignment response', function () {
    $trackingDetails = [
        'tracking_results' => [
            [
                'tracking_id' => '6XXX12345678',
                'consignment' => [
                    'events' => [
                        [
                            'location' => 'MEL',
                            'description' => 'Item Delivered',
                            'date' => '2017-09-18T14:35:07+10:00',
                        ],
                        [
                            'location' => 'MEL',
                            'description' => 'On Board for Delivery',
                            'date' => '2017-09-18T09:50:05+10:00',
                        ],
                    ],
                    'status' => 'Delivered in Full',
                ],
                'trackable_items' => [
                    [
                        'article_id' => '6XXX12345678EXP00001',
                        'product_type' => 'EXP',
                        'events' => [
                            [
                                'location' => 'MEL',
                                'description' => 'On Board for Delivery',
                                'date' => '2017-09-18T09:16:01+10:00',
                            ],
                            [
                                'location' => 'TRA',
                                'description' => 'Freight Handling',
                                'date' => '2017-09-15T16:33:29+10:00',
                            ],
                            [
                                'location' => 'TRA',
                                'description' => 'Picked Up',
                                'date' => '2017-09-15T09:04:05+10:00',
                            ],
                        ],
                        'status' => 'Item Delivered',
                    ],
                ],
            ],
        ],
    ];

    getMockAusPost($this->app, $trackingDetails);

    expect($this->app->make(Factory::class)->driver(AusPost::IDENTIFIER)->find('6XXX12345678'))
        ->toBeInstanceOf(TrackingDetails::class)
        ->identifier->toBe('6XXX12345678')
        ->status->toEqual(Status::Delivered)
        ->status->description()->toBe('Delivered')
        ->summary->toBe('All freight items in the consignment have been delivered.')
        ->estimatedDelivery->toBeNull()
        ->raw->toBe($trackingDetails);
});

it('can call `find` on the AusPost driver and handle a nested response', function () {
    $trackingDetails = [
        'tracking_results' => [
            [
                'tracking_id' => '33XXX0123456',
                'trackable_items' => [
                    [
                        'consignment_id' => '33XXX0123456',
                        'number_of_items' => 1,
                        'items' => [
                            [
                                'article_id' => '33XXX012345601000931502',
                                'product_type' => 'Parcel Post',
                                'events' => [
                                    [
                                        'location' => 'LIGHTSVIEW SA',
                                        'description' => 'Delivered - Left in a safe place',
                                        'date' => '2020-12-29T11:04:08+11:00',
                                    ],
                                    [
                                        'location' => 'REGENCY PARK SA',
                                        'description' => 'Onboard for delivery',
                                        'date' => '2020-12-29T07:36:39+11:00',
                                    ],
                                    [
                                        'location' => 'ADELAIDE (AU)',
                                        'description' => 'Received by Australia Post for transportation to processing facility',
                                        'date' => '2020-12-22T17:52:00+11:00',
                                    ],
                                    [
                                        'description' => 'Shipping information approved by Australia Post',
                                        'date' => '2020-12-16T03:15:58+11:00',
                                    ],
                                    [
                                        'description' => 'Shipping information received by Australia Post',
                                        'date' => '2020-12-15T23:59:32+11:00',
                                    ],
                                ],
                                'status' => 'Delivered',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    getMockAusPost($this->app, $trackingDetails);

    expect($this->app->make(Factory::class)->driver(AusPost::IDENTIFIER)->find('33XXX0123456'))
        ->toBeInstanceOf(TrackingDetails::class)
        ->identifier->toBe('33XXX0123456')
        ->status->toEqual(Status::Delivered)
        ->status->description()->toBe('Delivered')
        ->summary->toBe('The item or items in the shipment have been delivered.')
        ->estimatedDelivery->toBeNull()
        ->raw->toBe($trackingDetails);
});

it('can call `find` on the AusPost driver and handle another response format cause it changes a lot for some reason', function () {
    $trackingDetails = [
        'tracking_results' => [
            [
                'tracking_id' => 'I3XX00123456',
                'consignment' => [
                    'events' => [
                        [
                            'location' => 'PER',
                            'description' => 'Delivered',
                            'date' => '2020-12-17T11:58:17+11:00',
                        ],
                        [
                            'location' => 'PER',
                            'description' => 'On Board for Delivery',
                            'date' => '2020-12-17T09:24:25+11:00',
                        ],
                        [
                            'location' => 'PER',
                            'description' => 'Scanned in Transit',
                            'date' => '2020-12-16T17:23:30+11:00',
                        ],
                    ],
                    'status' => 'Delivered in Full',
                ],
                'trackable_items' => [
                    [
                        'article_id' => 'I3XX00123456FPP00001',
                        'product_type' => 'FPP',
                        'events' => [
                            [
                                'location' => 'PER',
                                'description' => 'On Board for Delivery',
                                'date' => '2020-12-17T09:24:24+11:00',
                            ],
                            [
                                'location' => 'PER',
                                'description' => 'Freight Handling',
                                'date' => '2020-12-17T09:15:40+11:00',
                            ],
                            [
                                'location' => 'PER',
                                'description' => 'Freight Handling',
                                'date' => '2020-12-17T02:35:36+11:00',
                            ],
                            [
                                'location' => 'PER',
                                'description' => 'Freight Handling',
                                'date' => '2020-12-16T17:23:30+11:00',
                            ],
                            [
                                'location' => 'PER',
                                'description' => 'Picked Up',
                                'date' => '2020-12-16T13:13:31+11:00',
                            ],
                        ],
                        'status' => 'Item Delivered',
                    ],
                ],
            ],
        ],
    ];

    getMockAusPost($this->app, $trackingDetails);

    expect($this->app->make(Factory::class)->driver(AusPost::IDENTIFIER)->find('I3XX00123456'))
        ->toBeInstanceOf(TrackingDetails::class)
        ->identifier->toBe('I3XX00123456')
        ->status->toEqual(Status::Delivered)
        ->status->description()->toBe('Delivered')
        ->summary->toBe('All freight items in the consignment have been delivered.')
        ->estimatedDelivery->toBeNull()
        ->raw->toBe($trackingDetails);
});

it('can handle a 429 error response', function () {
    $tooManyRequests = [
        'errors' => [
            [
                'message' => 'Too many requests',
                'error_code' => 'API_002',
                'error_name' => 'Too many requests',
            ],
        ],
    ];

    $httpMockHandler = new MockHandler([
        new Response(429, ['Content-Type' => 'application/json'], json_encode($tooManyRequests)),
    ]);

    $handlerStack = HandlerStack::create($httpMockHandler);

    $httpClient = new Client([
        'handler' => $handlerStack,
    ]);

    $this->app->make(Factory::class)->extend(AusPost::IDENTIFIER, fn () => new AusPost(
        apiKey: 'abcdefg',
        password: 'test',
        accountNumber: 'abc123',
        client: $httpClient,
    ));

    $this->app->make(Factory::class)
        ->driver(AusPost::IDENTIFIER)
        ->find('I3XX00123456');
})->throws(ApiLimitReachedException::class, 'The API limit of 10 requests per minute has been reached for the AusPost driver');

it('can handle a 200 response with 429 payload', function () {
    $tooManyRequests = [
        'errors' => [
            [
                'message' => 'Too many requests',
                'error_code' => 'API_002',
                'error_name' => 'Too many requests',
            ],
        ],
    ];

    $httpMockHandler = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode($tooManyRequests)),
    ]);

    $handlerStack = HandlerStack::create($httpMockHandler);

    $httpClient = new Client([
        'handler' => $handlerStack,
    ]);

    $this->app->make(Factory::class)->extend(AusPost::IDENTIFIER, fn () => new AusPost(
        apiKey: 'abcdefg',
        password: 'test',
        accountNumber: 'abc123',
        client: $httpClient,
    ));

    $this->app->make(Factory::class)
        ->driver(AusPost::IDENTIFIER)
        ->find('I3XX00123456');
})->throws(ApiLimitReachedException::class, 'The API limit of 10 requests per minute has been reached for the AusPost driver');

it('can handle a 403 error response', function () {
    $tooManyRequests = [
        'errors' => [
            [
                'message' => 'Undocumented unauthorised error',
                'error_code' => 'API_000',
                'error_name' => 'Unauthorised request',
            ],
        ],
    ];

    $httpMockHandler = new MockHandler([
        new Response(403, ['Content-Type' => 'application/json'], json_encode($tooManyRequests)),
    ]);

    $handlerStack = HandlerStack::create($httpMockHandler);

    $httpClient = new Client([
        'handler' => $handlerStack,
    ]);

    $this->app->make(Factory::class)->extend(AusPost::IDENTIFIER, fn () => new AusPost(
        apiKey: 'abcdefg',
        password: 'test',
        accountNumber: 'abc123',
        client: $httpClient,
    ));

    $this->app->make(Factory::class)
        ->driver(AusPost::IDENTIFIER)
        ->find('I3XX00123456');
})->throws(ApiAuthenticationFailedException::class, 'The API authentication failed for the AusPost driver');

it('can handle generic client exceptions', function () {
    $httpMockHandler = new MockHandler([
        new Response(419, ['Content-Type' => 'application/json'], json_encode([])),
    ]);

    $handlerStack = HandlerStack::create($httpMockHandler);

    $httpClient = new Client([
        'handler' => $handlerStack,
    ]);

    $this->app->make(Factory::class)->extend(AusPost::IDENTIFIER, fn () => new AusPost(
        apiKey: 'abcdefg',
        password: 'test',
        accountNumber: 'abc123',
        client: $httpClient,
    ));

    $exception = null;
    try {
        $this->app->make(Factory::class)
            ->driver(AusPost::IDENTIFIER)
            ->find('I3XX00123456');
    } catch (ClientException $exception) {
    }

    expect($exception)->toBeInstanceOf(ClientException::class)
        ->and($exception->getCode())->toBe(419)
        ->and(trim(preg_replace('/[\s\n\r]+/', ' ', $exception->getMessage())))
            ->toBe('Client error: `GET /shipping/v1/track?tracking_ids=I3XX00123456` resulted in a `419 ` response: []');
});
