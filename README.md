### Docker Run
```
docker run \
--detach \
--name memcached \
--restart unless-stopped \
memcached:latest

docker run \
--detach \
--name url-shortener \
--restart unless-stopped \
--link memcached \
--publish 1816:1816 \
--volume url-shortener-config:/config \
bmoorman/url-shortener:latest
```

### Docker Compose
```
version: "3.7"
services:
  memcached:
    image: memcached:latest
    container_name: memcached
    restart: unless-stopped

   url-shortener:
    image: bmoorman/url-shortener:latest
    container_name: url-shortener
    restart: unless-stopped
    depends_on:
      - memcached
    ports:
      - "1816:1816"
    volumes:
      - url-shortener-config:/config

volumes:
  url-shortener-config:
```

### Environment Variables
|Variable|Description|Default|
|--------|-----------|-------|
|TZ|Sets the timezone|`America/Denver`|
|HTTPD_SERVERNAME|Sets the vhost servername|`localhost`|
|HTTPD_PORT|Sets the vhost port|`1816`|
|HTTPD_SSL|Set to anything other than `SSL` (e.g. `NO_SSL`) to disable SSL|`SSL`|
|HTTPD_REDIRECT|Set to anything other than `REDIRECT` (e.g. `NO_REDIRECT`) to disable SSL redirect|`REDIRECT`|
|MEMCACHED_HOST|Sets the Memcached host|`memcached`|
|MEMCACHED_PORT|Sets the Memcached port|`11211`|
