<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Nugsoft\SignalBridge\SignalBridgeClient;

// Initialize the client
$client = new SignalBridgeClient(
  token: getenv('SIGNALBRIDGE_TOKEN') ?: 'your_api_token_here',
  // baseUrl defaults to SignalBridgeClient::DEFAULT_BASE_URL (production).
  // Pass baseUrl: '...' to point at another environment.
);

// Schedule a reminder for tomorrow at 9 AM
$tomorrow9am = (new DateTime('tomorrow 9:00:00'))->format('c');

try {
  $result = $client->sendSms(
    recipient: '256700000000',
    message: 'Reminder: Your appointment is scheduled for tomorrow at 10 AM.',
    options: [
      'sender_id' => 'NUGSOFT',
      'scheduled_at' => $tomorrow9am,
      'metadata' => [
        'type' => 'appointment_reminder',
        'appointment_id' => 456
      ]
    ]
  );

  echo "Message scheduled successfully!\n";
  echo "Message ID: {$result['data']['message_id']}\n";
  echo "Scheduled for: {$tomorrow9am}\n";
} catch (\Exception $e) {
  echo "Error: {$e->getMessage()}\n";
}
