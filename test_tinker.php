$executor = app(\App\Services\Rule\RuleActionExecutor::class);
$action = [
    'api' => '/api/v1/ledgers',
    'api_payload' => json_decode('{"tg_gid":-5174039148,"tg_uid":6427311287,"tg_belong_uid":6427311287,"tg_msg_id":440001,"amount":"6","is_delete":false}', true),
    'mode' => 'api_and_reply',
    'reply_template' => '记账成功，金额={{1}}，流水ID={{result.data.id}}'
];
$matches = [1 => '6'];
$context = [
    'tg_gid' => -5174039148,
    'tg_uid' => 6427311287,
    'tg_msg_id' => 440001
];

$reflection = new ReflectionClass($executor);

$executeApiIfNeeded = $reflection->getMethod('executeApiIfNeeded');
$executeApiIfNeeded->setAccessible(true);
$apiResult = $executeApiIfNeeded->invoke($executor, $action, true);

$renderReplyText = $reflection->getMethod('renderReplyText');
$renderReplyText->setAccessible(true);
$replyText = $renderReplyText->invoke($executor, $action, $matches, $context, $apiResult);

echo "apiResult.ok: " . ($apiResult['ok'] ? 'true' : 'false') . "\n";
echo "apiResult.status: " . $apiResult['status'] . "\n";
echo "apiResult.body.id: " . (isset($apiResult['body']['id']) ? $apiResult['body']['id'] : 'null') . "\n";
echo "apiResult.body.data.id: " . (isset($apiResult['body']['data']['id']) ? $apiResult['body']['data']['id'] : 'null') . "\n";
echo "replyText: " . $replyText . "\n";
