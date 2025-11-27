<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Nugsoft\SignalBridge\SignalBridgeClient;

// Initialize the client
$client = new SignalBridgeClient(
  token: 'your_api_token_here',
  baseUrl: 'https://signal-bridge.nugsoftstagging.com/api'
);

try {
  // Get transactions for the current month
  $startDate = date('Y-m-01');
  $endDate = date('Y-m-t');

  $transactions = $client->getTransactions([
    'type' => 'debit',
    'start_date' => $startDate,
    'end_date' => $endDate,
    'per_page' => 50
  ]);

  echo "📊 Transaction Report ({$startDate} to {$endDate})\n";
  echo str_repeat('=', 60) . "\n\n";

  $totalCost = 0;
  $messageCount = 0;

  foreach ($transactions['data'] as $tx) {
    $totalCost += $tx['amount'];
    $messageCount++;

    echo "Date: {$tx['created_at']}\n";
    echo "Amount: -{$tx['amount']} {$tx['currency']}\n";
    echo "Description: {$tx['description']}\n";
    echo "Balance after: {$tx['balance_after']} {$tx['currency']}\n";
    echo str_repeat('-', 60) . "\n";
  }

  echo "\nSummary:\n";
  echo "Total messages sent: {$messageCount}\n";
  echo "Total cost: {$totalCost} UGX\n";
  echo "Average cost per message: " . ($messageCount > 0 ? round($totalCost / $messageCount, 2) : 0) . " UGX\n";
} catch (\Exception $e) {
  echo "Error: {$e->getMessage()}\n";
}
