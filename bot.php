<?php
require 'vendor/autoload.php';

use Telegram\Bot\Api;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

\Sentry\init([
    'dsn' => $_ENV['SENTRY_DSN'],
]);

$log = new Logger('bot');
$log->pushHandler(new StreamHandler(__DIR__ . '/bot.log', Logger::DEBUG));

try {
    $bot = new Api($_ENV['TG_API_TOKEN']);
} catch (\Telegram\Bot\Exceptions\TelegramSDKException $e) {
    $log->error('Bot initialization failed: ' . $e->getMessage());
    \Sentry\captureException($e);
    die();
}

function formatAnswers(array $questionAnswers): string
{
    $result = '';

    foreach ($questionAnswers as $key => $value) {
        $result .= $key . ' - ' . $value . PHP_EOL;
    }

    return $result;
}

require_once __DIR__ . '/introduction.php';
require_once __DIR__ . '/questions.php';
require_once __DIR__ . '/answers.php';
require_once __DIR__ . '/detailed_answers.php';
/**
 * @var string $introduction
 * @var array $questions
 * @var array $answers
 * @var array $detailedAnswers
 */

$lastUpdateId = 0;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

while (true) {
    try {
        $updates = $bot->getUpdates(['offset' => $lastUpdateId + 1, 'limit' => 1, 'timeout' => 10]);

        foreach ($updates as $update) {
            if (isset($update['message'])) {
                $chatId = $update['message']['chat']['id'];
                $text = trim($update['message']['text']);

                if (!isset($_SESSION['users'][$chatId])) {
                    $_SESSION['users'][$chatId] = [
                        'step' => 0,
                        'scores' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0]
                    ];
                }

                $userSession = &$_SESSION['users'][$chatId];

                if (str_starts_with($text, '/')) {
                    if ($text == '/start') {
                        $userSession = [
                            'step' => 1,
                            'scores' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0]
                        ];
                        $keyboard = json_encode([
                            'keyboard' => [["A", "B"], ["C", "D"]],
                            'one_time_keyboard' => true,
                            'resize_keyboard' => true
                        ]);
                        $bot->sendMessage([
                            'chat_id' => $chatId,
                            'text' => $introduction,
                            'parse_mode' => 'HTML',
                        ]);
                        $bot->sendMessage([
                            'chat_id' => $chatId,
                            'text' => $questions[0] . PHP_EOL . formatAnswers($detailedAnswers[0]),
                            'reply_markup' => $keyboard,
                            'parse_mode' => 'HTML',
                        ]);
                    } else {
                        $bot->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "Выберите ответ из предложенных",
                            'parse_mode' => 'HTML',
                        ]);
                    }
                } else {
                    if ($userSession['step'] > 0) {
                        if (in_array($text, array_keys($answers))) {
                            $userSession['scores'][$text]++;
                            $userSession['step']++;

                            if ($userSession['step'] <= count($questions)) {
                                $keyboard = json_encode([
                                    'keyboard' => [["A", "B"], ["C", "D"]],
                                    'one_time_keyboard' => true,
                                    'resize_keyboard' => true
                                ]);
                                $bot->sendMessage([
                                    'chat_id' => $chatId,
                                    'text' => $questions[$userSession['step'] - 1] . PHP_EOL . formatAnswers($detailedAnswers[$userSession['step'] - 1]),
                                    'reply_markup' => $keyboard,
                                    'parse_mode' => 'HTML',
                                ]);
                            } else {
                                arsort($userSession['scores']);
                                $result = array_key_first($userSession['scores']);
                                require_once __DIR__ . '/result_message.php';
                                /**
                                 * @var $result_text
                                 */

                                $bot->sendMessage([
                                    'chat_id' => $chatId,
                                    'text' => $result_text,
                                    'parse_mode' => 'HTML',
                                ]);
                                unset($_SESSION['users'][$chatId]);
                            }
                        } else {
                            $bot->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "Пожалуйста, выберите A, B, C или D.",
                                'parse_mode' => 'HTML',
                            ]);
                        }
                    } else {
                        $bot->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "Send /start to begin the quiz.",
                            'parse_mode' => 'HTML',
                        ]);
                    }
                }
            }

            $lastUpdateId = $update['update_id'];
        }

        sleep(1);
    } catch (\Exception $e) {
        $log->error('Error fetching updates: ' . $e->getMessage());
        \Sentry\captureException($e);
    }
}
