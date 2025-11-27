<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Nugsoft\SignalBridge\SignalBridgeClient;
use Nugsoft\SignalBridge\Exceptions\InsufficientBalanceException;
use Nugsoft\SignalBridge\Exceptions\ValidationException;

// Initialize the client
$client = new SignalBridgeClient(
  token: 'your_api_token_here',
  baseUrl: 'https://signal-bridge.nugsoftstagging.com/api'
);

// Example 1: Send a simple SMS
try {
  $result = $client->sendSms(
    recipient: '256700000000',
    message: 'Hello from PHP SDK!',
    options: [
      'sender_id' => 'NUGSOFT',
      'metadata' => [
        'user_id' => 123,
        'action' => 'test_message'
      ]
    ]
  );

  echo "Message sent successfully!\n";
  echo "Message ID: {$result['data']['message_id']}\n";
  echo "Cost: {$result['data']['cost']} UGX\n";
  echo "Balance remaining: {$result['data']['balance_after']} UGX\n\n";
} catch (InsufficientBalanceException $e) {
  echo "Insufficient balance\n";
  echo "Required: {$e->getRequiredBalance()} UGX\n";
  echo "Available: {$e->getCurrentBalance()} UGX\n";
} catch (ValidationException $e) {
  echo "Validation error: {$e->getMessage()}\n";
  print_r($e->getErrors());
} catch (\Exception $e) {
  echo "Error: {$e->getMessage()}\n";
}

// Example 2: Check balance before sending
try {
  $balance = $client->getBalance('UGX');

  echo "Current Balance\n";
  echo "Balance: {$balance['balance']} UGX\n";
  echo "Available: {$balance['available_balance']} UGX\n";
  echo "Segment price: {$balance['segment_price']} UGX\n\n";
} catch (\Exception $e) {
  echo "Error getting balance: {$e->getMessage()}\n";
}

// Example 3: Calculate message cost
$message = 'This is a test message to calculate segments and cost';
$segments = $client->calculateSegments($message);
$estimatedCost = $client->estimateCost($message, 75.00);

echo "Message Analysis\n";
echo "Message length: " . mb_strlen($message) . " characters\n";
echo "Segments: {$segments}\n";
echo "Estimated cost: {$estimatedCost} UGX\n\n";
