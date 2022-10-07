<?php

declare(strict_types=1);

namespace ParcelTrap\AusPost;

use GrahamCampbell\GuzzleFactory\GuzzleFactory;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use ParcelTrap\Contracts\Driver;
use ParcelTrap\DTOs\TrackingDetails;
use ParcelTrap\Enums\Status;
use ParcelTrap\Exceptions\ApiAuthenticationFailedException;
use ParcelTrap\Exceptions\ApiLimitReachedException;

class AusPost implements Driver
{
    public const IDENTIFIER = 'auspost';

    public const BASE_URI = 'https://digitalapi.auspost.com.au';

    public const API_LIMIT_PERIOD = 'minute';

    public const API_LIMIT_PER_PERIOD = 10;

    private ClientInterface $client;

    public function __construct(private readonly string $apiKey, private readonly string $password, private readonly string $accountNumber, ?ClientInterface $client = null)
    {
        $this->client = $client ?? GuzzleFactory::make(['base_uri' => self::BASE_URI]);
    }

    public function find(string $identifier, array $parameters = []): TrackingDetails
    {
        // find() only supports returning a single TrackingDetails object so we can only search for one
        if (str_contains($identifier, ',')) {
            $identifier = substr($identifier, 0, strpos($identifier, ','));
        }

        $response = $this->client->request('GET', '/shipping/v1/track', [
            RequestOptions::HEADERS => $this->getHeaders(),
            RequestOptions::QUERY => array_merge(['tracking_ids' => $identifier], $parameters),
        ]);

        // No documentation on the API, so this is a guess
        if (in_array($response->getStatusCode(), [401, 403])) {
            throw new ApiAuthenticationFailedException(
                driver: $this,
            );
        }

        // Aus Post limit the API to 10 requests per minute
        if ($response->getStatusCode() === 429) {
            throw new ApiLimitReachedException(
                driver: $this,
                limit: static::API_LIMIT_PER_PERIOD,
                period: static::API_LIMIT_PERIOD,
            );
        }

        /** @var array $json */
        $json = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

        // Get the first (only) tracking result
        $result = $json['tracking_results'][0] ?? null;

        // Extract the status and error codes where applicable
        $statusCode = strtolower(
            $result['status'] ?? (
                $result['consignment']['status'] ?? (
                    $result['trackable_items'][0]['items'][0]['status'] ?? 'unknown'
                )
            )
        );
        $errorCode = strtolower(
            $result['errors'][0]['code'] ?? (
                $result['errors'][0]['error_code'] ?? 'unknown'
            )
        );

        // Convert the status code to a ParcelTrap status
        $status = $this->mapStatus($statusCode);
        $summary = $this->mapStatusToSummary($statusCode);

        // If error code is known, convert it to a ParcelTrap status
        if ($errorCode !== null) {
            // Fail safe in case the API does not return 429 when the limit is reached
            if ($errorCode === 'API_002') {
                throw new ApiLimitReachedException(
                    driver: $this,
                    limit: static::API_LIMIT_PER_PERIOD,
                    period: static::API_LIMIT_PERIOD,
                );
            }

            $status = $this->mapErrorCodeToStatus($errorCode) ?? $status;
            $summary = $this->mapErrorCodeToSummary($errorCode) ?? $summary;
        }

        return new TrackingDetails(
            identifier: $result['tracking_id'] ?? $identifier,
            status: $status,
            summary: $summary,
            estimatedDelivery: null,
            events: $result['trackable_items'][0]['events'] ?? [],
            raw: $json,
        );
    }

    private function mapStatus(string $status): Status
    {
        return match ($status) {
            'created' => Status::Pending,
            'sealed' => Status::Pending,
            'initiated' => Status::Pre_Transit,
            'in transit' => Status::In_Transit,
            'delivered' => Status::Delivered,
            'awaiting collection' => Status::Pre_Transit,
            'possible delay' => Status::In_Transit,
            'unsuccessful pickup' => Status::Failure,
            'article damaged' => Status::In_Transit,
            'cancelled' => Status::Cancelled,
            'held by courier' => Status::In_Transit,
            'cannot be delivered' => Status::Failure,
            'track items for detailed delivery information' => Status::Unknown,

            'at delivery depot' => Status::Pre_Transit,
            'booked in' => Status::Pending,
            'confirmed' => Status::Pending,
            'deleted' => Status::Cancelled,
            'delivered in full' => Status::Delivered,
            'delivered' => Status::Delivered,
            'final shortage' => Status::Delivered,
            'incomplete' => Status::Pre_Transit,
            'in transit' => Status::In_Transit,
            'on board for delivery' => null,
            'partial delivery' => Status::Delivered,
            'partial pickup' => Status::In_Transit,
            'picked up' => Status::In_Transit,
            're-consigned' => Status::Pre_Transit,
            'to be re-delivered' => Status::In_Transit,
            'ready for pickup' => Status::Pre_Transit,
            'unconfirmed' => Status::Pre_Transit,
            'unsuccessful delivery' => Status::Failure,

            'at delivery depot' => Status::Pre_Transit,
            'booked in' => Status::Pending,
            'confirmed' => Status::Pre_Transit,
            'deleted' => Status::Cancelled,
            'item delivered' => Status::Delivered,
            'final shortage' => Status::Failure,
            'incomplete' => Status::Pre_Transit,
            'in transit' => Status::In_Transit,
            'on board for delivery' => Status::In_Transit,
            'picked up' => Status::In_Transit,
            're-consigned' => Status::In_Transit,
            'to be re-delivered' => Status::In_Transit,
            'ready for pickup' => Status::Pre_Transit,
            'unconfirmed' => Status::Pre_Transit,
            'unsuccessful delivery' => Status::Failure,

            default => Status::Unknown,
        };
    }

    private function mapStatusToSummary(string $status): string
    {
        return match ($status) {
            'created' => 'The item or items in the shipment have been created, but have not been finalised in an order.',
            'sealed' => 'The shipment has been added to an order.',
            'initiated' => 'The item or items in the shipment have been finalised in an order and will be delivered when the parcels are received by Australia Post.',
            'in transit' => 'The item or items in the shipment are being delivered.',
            'delivered' => 'The item or items in the shipment have been delivered.',
            'awaiting collection' => 'The item or items in the shipment are awaiting collection.',
            'possible delay' => 'A delay to the delivery of item or items in the shipment is highly likely. Refer to the Australia Post website or call 13 76 78 (13 POST) for more information.',
            'unsuccessful pickup' => 'The item or items in the shipment could not be collected by Australia Post for delivery.',
            'article damaged' => 'The item or items in the shipment were damaged during delivery.',
            'cancelled' => 'Delivery of item or items in the shipment was cancelled.',
            'held by courier' => 'The item or items in the shipment have been held by the courier.',
            'cannot be delivered' => 'The item or items in the shipment cannot be delivered as addressed.',
            'track items for detailed delivery information' => 'A shipment level delivery summary cannot be determined, as the items in the shipment are at differing delivery statuses. Track the individual items in the shipment for detailed delivery information.',

            'at delivery depot' => 'Consignment is at carrier depot closest to receiver.',
            'booked in' => 'Consignment is at carrier depot closest to receiver.',
            'confirmed' => 'Sender has provided consignment information to carrier in preparation for shipment.',
            'deleted' => 'Sender has deleted consignment information prepared earlier in preparation for shipment.',
            'delivered in full' => 'All freight items in the consignment have been delivered.',
            'delivered' => 'Some or all freight items in the consignment have been delivered and Proof of Delivery is available.',
            'final shortage' => 'Delivery is complete but not all items were able to be delivered..',
            'incomplete' => 'Sender has partially completed consignment information for carrier in preparation for shipment.',
            'in transit' => 'Consignment is in transit between two carrier depots (initial/intermediate/final).',
            'on board for delivery' => 'Consignment is in a local delivery vehicle.',
            'partial delivery' => 'Some but not all freight items in the consignment have been delivered.',
            'partial pickup' => 'Some but not all items in the consignment were scanned by carrier on pickup from sender.',
            'picked up' => 'Carrier has picked up from the sender all freight items in the consignment.',
            're-consigned' => 'Consignment information incorrect or incomplete. Corrected information supplied in a new consignment..',
            'to be re-delivered' => 'Consignment has been returned to local carrier depot as undeliverable: to be re-delivered on a following day.',
            'ready for pickup' => 'Consignment awaiting pickup by carrier.',
            'unconfirmed' => 'Sender has completed consignment information for carrier in preparation for shipment but has not yet finalised the consignment.',
            'unsuccessful delivery' => 'Consignment could not be delivered.',

            'at delivery depot' => 'Freight item is at carrier depot closest to receiver.',
            'booked in' => 'Freight item held at carrier depot closest to receiver until a date and time authorised by receiver.',
            'confirmed' => 'Sender has provided consignment information to carrier in preparation for shipment.',
            'deleted' => 'Sender has deleted consignment information prepared earlier in preparation for shipment.',
            'item delivered' => 'Freight item has been delivered.',
            'final shortage' => 'The freight item was not able to be delivered and delivery of the consignment is complete..',
            'incomplete' => 'Sender has partially completed consignment information for carrier in preparation for shipment.',
            'in transit' => 'Freight item is in transit between two carrier depots (initial/intermediate/final).',
            'on board for delivery' => 'Freight item is in a local delivery vehicle.',
            'picked up' => 'Carrier has picked up freight item from the sender.',
            're-consigned' => 'Consignment information was incorrect or incomplete. Corrected information supplied in a new consignment..',
            'to be re-delivered' => 'Freight item has been returned to local carrier depot as undeliverable: to be re-delivered on a following day.',
            'ready for pickup' => 'Freight item awaiting pickup by carrier.',
            'unconfirmed' => 'Sender has completed consignment information for carrier in preparation for shipment but has not yet finalised the consignment.',
            'unsuccessful delivery' => 'Freight item could not be delivered.',

            default => 'An unknown Australia Post status',
        };
    }

    private function mapErrorCodeToStatus(string $code): ?Status
    {
        return match ($code) {
            'esb-10001' => Status::Not_Found,
            'esb-10002' => Status::Not_Found,
            'esb-20010' => Status::Failure,
            'esb-20050' => Status::Failure,
            '51100' => Status::Failure,
            '51101' => Status::Unknown,
            '51102' => Status::Unknown,
            '51103' => Status::Unknown,
            '51104' => Status::Not_Found,
            default => null,
        };
    }

    private function mapErrorCodeToSummary(string $code): ?string
    {
        return match ($code) {
            'esb-10001' => 'Invalid Tracking ID: The requested consignment could not be found.',
            'esb-10002' => 'Product Not Trackable: The query article or query consignment call identified that the article or consignment respectively is not trackable.',
            'esb-20010' => 'System Error: An internal technical error occurred.',
            'esb-20050' => 'System Error: An internal technical error occurred.',
            '51100' => 'Tracking ID Missing: The request must contain at least one tracking id.',
            '51101' => 'Too many AP tracking IDs: The request must contain 10 or less AP article ids, consignment ids, or barcode ids.',
            '51102' => 'Too many SP tracking IDs: The request must contain 10 or less StarTrack consignment ids.',
            '51103' => 'Tracking IDs Mix of AP and ST: The request must only contain tracking ids for either StarTrack consignment ids or a mix of AP article ids, consignment ids, or barcode ids.',
            '51104' => 'Invalid Tracking ID: One or more submitted tracking ids could not be found.',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    private function getHeaders(array $headers = []): array
    {
        return array_merge([
            'Authorization' => 'Basic '.base64_encode($this->apiKey.':'.$this->password),
            'Account-Number' => $this->accountNumber,
            'Accept' => 'application/json',
        ], $headers);
    }
}
