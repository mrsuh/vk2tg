# vk2tg

#### Resend wall posts from vk group to tg channel 

## Installation
* create an app on vk.com and get app token https://vk.com/apps?act=manage
* create a tg bot
* create a tg channel
* add a bot to a channel as admin

```sh
cp .env.example .env
```

.env
```dotenv
TG_BOT_TOKEN=tg-bot-token
TG_PROXY_DSN=socks5://username:pass@host:port //optional
TG_CHANNEL_ID=-tg-channel-id
VK_TOKEN=vk-token
VK_GROUP_ID=-vk-group-id
CHECK_TIMEOUT_SEC=10
REQUEST_TIMEOUT_SEC=5
```

## Usage
```sh
docker pull mrsuh/vk2tg
docker run --env-file .env -v $(pwd)/vk_last_post_date.txt:/app/vk_last_post_date.txt -d mrsuh/vk2tg
docker logs -f <container_id>
```

