<?php

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use TelegramBot\Api\BotApi;

require __DIR__ . '/../vendor/autoload.php';

class LimitedList
{
    /** @var int[] */
    private $list;

    /** @var int */
    private $limit;

    public function __construct(int $limit)
    {
        $this->list  = [];
        $this->limit = $limit;
    }

    /**
     * @param $item
     */
    public function push(int $item): void
    {
        while (count($this->list) >= $this->limit) {
            array_shift($this->list);
        }

        array_push($this->list, $item);
    }

    /**
     * @param $item
     * @return bool
     */
    public function has($item): bool
    {
        return in_array($item, $this->list);
    }
}

class Storage
{
    /** @var int */
    private $lastDate;

    /** @var LimitedList */
    private $lastIds;

    public function __construct()
    {
        $this->lastDate = time();
        $this->lastIds  = new LimitedList(20);
    }

    public function setLastDate(int $lastDate): void
    {
        $this->lastDate = $lastDate;
    }

    public function getLastDate(): int
    {
        return $this->lastDate;
    }

    public function addId(int $id): void
    {
        $this->lastIds->push($id);
    }

    public function hasId(int $id): bool
    {
        return $this->lastIds->has($id);
    }

    public function save(): void
    {
        file_put_contents(__DIR__ . '/../storage.json', serialize($this));
    }

    public static function load(): Storage
    {
        $filePath = __DIR__ . '/../storage.json';
        if (!file_exists($filePath)) {
            return new Storage();
        }

        $content = file_get_contents($filePath);
        if (empty($content)) {
            return new Storage();
        }

        $storage = unserialize($content);
        if (!$storage instanceof Storage) {
            return new Storage();
        }

        return $storage;
    }
}

class Vk2Tg
{
    /** @var BotApi */
    private $tgBot;

    /** @var string */
    private $tgChannelId;

    /** @var string */
    private $vkGroupId;

    /** @var string */
    private $vkToken;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var Storage */
    private $storage;

    public function __construct()
    {
        $this->vkToken     = getenv('VK_TOKEN');
        $this->vkGroupId   = getenv('VK_GROUP_ID');
        $this->storage     = Storage::load();
        $this->tgBot       = new BotApi(getenv('TG_BOT_TOKEN'));
        $this->tgChannelId = getenv('TG_CHANNEL_ID');
        $tgProxyDSN        = getenv('TG_PROXY_DSN');
        if (!empty($tgProxyDSN)) {
            $this->tgBot->setProxy($tgProxyDSN);
        }

        $this->logger = new Logger('vk2tg');
        $this->logger->pushHandler(new StreamHandler(STDOUT, Logger::DEBUG));
    }

    public function resend(): void
    {
        $requestTimeout = stream_context_create(['http' => ['timeout' => getenv('REQUEST_TIMEOUT_SEC')]]);

        $this->logger->debug('Request posts');

        $vkLink     = sprintf('https://api.vk.com/method/wall.get?v=5.64&owner_id=%s&access_token=%s&count=5', $this->vkGroupId, $this->vkToken);
        $vkResponse = file_get_contents($vkLink, false, $requestTimeout);
        $vkData     = json_decode($vkResponse, true);

        if (!is_array($vkData)) {
            $fileName = sprintf('invalid_response_%d.txt', time());
            $this->logger->error('invalid response', ['file' => $fileName]);
            file_put_contents($fileName, $vkResponse);

            return;
        }

        if (!isset($vkData['response']['items'])) {
            $fileName = sprintf('empty_response_%d.txt', time());
            $this->logger->error('empty response', ['file' => $fileName]);
            file_put_contents($fileName, $vkResponse);

            return;
        }

        $vkLastPostDateTmp = 0;
        foreach ($vkData['response']['items'] as $vkIndex => $vkItem) {
            $vkItemId = (int)$vkItem['id'];
            if (isset($vkItem['is_pinned']) && $vkItem['is_pinned']) {
                $this->logger->debug('Skip post', ['reason' => 'is pinned', 'id' => $vkItemId]);
                continue;
            }

            if ((int)$vkItem['date'] <= $this->storage->getLastDate()) {
                $this->logger->debug('Skip post', ['reason' => 'already posted by date', 'id' => $vkItemId, 'date' => $vkItem['date']]);
                break;
            }

            if (0 === $vkLastPostDateTmp && $this->storage->getLastDate() < (int)$vkItem['date']) {
                $this->logger->debug('Set new last post date', ['date' => $vkItem['date']]);
                $vkLastPostDateTmp = (int)$vkItem['date'];
            }

            if ((int)$vkItem['from_id'] !== (int)$this->vkGroupId) {
                $this->logger->debug('Skip post', ['reason' => 'post by alien', 'id' => $vkItemId]);
                continue;
            }

            if ($vkItem['marked_as_ads']) {
                $this->logger->debug('Skip post', ['reason' => 'marked as ads', 'id' => $vkItemId]);
                continue;
            }

            if ($this->storage->hasId($vkItemId)) {
                $this->logger->debug('Skip post', ['reason' => 'already posted by id', 'id' => $vkItemId]);
                continue;
            }

            $this->storage->addId($vkItemId);

            $text   = $vkItem['text'];
            $links  = [];
            $photos = [];
            $videos = [];
            foreach ($vkItem['attachments'] as $vkAttachment) {
                switch ($vkAttachment['type']) {
                    case 'link':
                        $links[$vkAttachment['link']['title']] = $vkAttachment['link']['url'];
                        break;
                    case 'photo':
                        foreach (['photo_807', 'photo_800', 'photo_604', 'photo_1280'] as $vkPhotoResolution) {
                            if (isset($vkAttachment['photo'][$vkPhotoResolution])) {
                                $photos[] = $vkAttachment['photo'][$vkPhotoResolution];
                                break;
                            }
                        }
                        break;
                    case 'video':

                        $link = sprintf('https://vk.com/video%s_%s', $vkAttachment['video']['owner_id'], $vkAttachment['video']['id']);

                        if (isset($vkAttachment['video']['platform'])) {
                            preg_match('/<iframe [^>]+src=\\\"([^\"]+)\?/', file_get_contents($link, false, $requestTimeout), $match);
                        }

                        if (isset($match[1])) {
                            $videos[] = str_replace('\\', '', $match[1]);
                            break;
                        }

                        $videos[] = $link;
                        break;
                }
            }

            if (empty($text) && !empty($photos)) {
                $this->logger->info('Send new post', ['photos' => array_values($photos), 'videos' => array_values($videos), 'id' => $vkItemId]);
                foreach ($photos as $photo) {
                    try {
                        $this->tgBot->sendPhoto($this->tgChannelId, $photo);
                    } catch (\Exception $e) {
                        $this->logger->error('send Photo', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString(), 'id' => $vkItemId]);
                    }
                }

                break;
            }

            foreach ($links as $title => $url) {
                $text .= sprintf("\n<a href='%s'>%s</a>\n", $url, $title);
            }

            foreach ($photos as $title => $url) {
                $text .= sprintf("\n<a href='%s'>%s</a>\n", $url, $url);
            }

            foreach ($videos as $title => $url) {
                $text .= sprintf("\n<a href='%s'>%s</a>\n", $url, $url);
            }

            $this->logger->info('Send new post', ['text' => $text]);
            try {
                if (!empty($text)) {
                    $this->tgBot->sendMessage($this->tgChannelId, $text, 'html');
                }

            } catch (\Exception $e) {
                $this->logger->error('send Message', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            }
        }

        if (0 !== $vkLastPostDateTmp) {
            $this->storage->setLastDate($vkLastPostDateTmp);
        }

        $this->storage->save();
    }
}

$vk2tg   = new Vk2Tg();
$timeout = (int)getenv('CHECK_TIMEOUT_SEC');

while (true) {
    $vk2tg->resend();
    sleep($timeout);
}