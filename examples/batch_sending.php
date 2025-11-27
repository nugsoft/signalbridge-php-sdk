<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Nugsoft\SignalBridge\SignalBridgeClient;

// Initialize the client
$client = new SignalBridgeClient(
    token: 'your_api_token_here',
    baseUrl: 'https://signal-bridge.nugsoftstagging.com/api'
);

// Simulate a list of students
$students = [
    ['phone' => '256700000000', 'name' => 'John Doe', 'score' => 85, 'grade' => 'A'],
    ['phone' => '256700000001', 'name' => 'Jane Smith', 'score' => 92, 'grade' => 'A+'],
    ['phone' => '256700000002', 'name' => 'Bob Johnson', 'score' => 78, 'grade' => 'B'],
];

// Build batch messages
$messages = [];
foreach ($students as $student) {
    $messages[] = [
        'recipient' => $student['phone'],
        'message' => "Hi {$student['name']}, your exam results: Score {$student['score']}/100, Grade: {$student['grade']}",
        'metadata' => [
            'student_name' => $student['name'],
            'score' => $student['score'],
            'type' => 'exam_results'
        ]
    ];
}

try {
    echo "Sending batch SMS to " . count($messages) . " students...\n\n";

    $result = $client->sendBatch($messages, [
        'sender_id' => 'NUGSOFT'
    ]);

    echo "Batch processing complete!\n";
    echo "Total messages: {$result['data']['total']}\n";
    echo "Successful: {$result['data']['successful']}\n";
    echo "Failed: {$result['data']['failed']}\n\n";

    // Show details of each message
    foreach ($result['data']['messages'] as $msg) {
        if ($msg['success']) {
            echo "{$msg['recipient']}: Message ID {$msg['data']['message_id']}\n";
        } else {
            echo "{$msg['recipient']}: {$msg['error']}\n";
        }
    }

} catch (\Exception $e) {
    echo "Batch sending failed: {$e->getMessage()}\n";
}
