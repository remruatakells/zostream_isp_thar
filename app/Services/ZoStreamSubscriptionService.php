<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZoStreamSubscriptionService
{
    public function createOrder(Customer $customer): array
    {
        $customer->loadMissing(['package', 'branch']);
        $apiKey = (string) config('services.zostream_subscription.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('ZOSTREAM_EXTERNAL_API_KEY is not configured.');
        }
        if (! $customer->package || (float) $customer->package->price <= 0) {
            throw new RuntimeException('The customer does not have a payable package.');
        }
        $packageAmount = (float) $customer->package->price;
        $ottDeduction = max(0, (float) ($customer->branch?->ott_deduction
            ?? config('services.zostream_subscription.ott_deduction', 50)));
        $operatorPercentage = min(100, max(0, (float) ($customer->branch?->operator_percentage
            ?? config('services.zostream_subscription.operator_percentage', 20))));
        $distributableAmount = $packageAmount - $ottDeduction;
        $operatorCommission = $distributableAmount * ($operatorPercentage / 100);
        $wifiShare = $distributableAmount - $operatorCommission;
        $payableAmount = $wifiShare + $ottDeduction;
        if ($payableAmount <= 0) {
            throw new RuntimeException('The package amount must be greater than the OTT deduction.');
        }
        if (blank($customer->phone)) {
            throw new RuntimeException('The customer phone number is required for ZoStream payment.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(20)
                ->connectTimeout(7)
                ->withHeaders([
                    'X-Api-Key' => $apiKey,
                    'X-RZ-Env' => (string) config('services.zostream_subscription.environment', 'SANDBOX'),
                ])
                ->post(rtrim((string) config('services.zostream_subscription.base_url'), '/').'/api/v3.0/external/subscription-history', [
                    'phone_number' => $customer->phone,
                    'amount' => $payableAmount,
                    'currency' => 'INR',
                    'meta' => [
                        'source_name' => (string) config('services.zostream_subscription.source_name', 'zostream-isp-panel'),
                    ],
                ])
                ->throw();
        } catch (RequestException $e) {
            $detail = $e->response?->json('message') ?: 'ZoStream subscription API request failed.';
            throw new RuntimeException($detail, previous: $e);
        }

        $data = $response->json();
        $order = data_get($data, 'razorpay_order');
        if (data_get($data, 'status') !== 'success' || ! is_array($order) || blank($order['id'] ?? null)) {
            throw new RuntimeException((string) (data_get($data, 'message') ?: 'ZoStream API did not return a Razorpay order.'));
        }
        $expectedAmount = (int) round($payableAmount * 100);
        if ((int) ($order['amount'] ?? 0) !== $expectedAmount || strtoupper((string) ($order['currency'] ?? '')) !== 'INR') {
            throw new RuntimeException('ZoStream API returned an order with an unexpected amount or currency.');
        }
        if (blank(data_get($data, 'razorpay_key_id'))) {
            throw new RuntimeException('ZoStream API did not return the Razorpay key ID.');
        }

        return $data;
    }
}
