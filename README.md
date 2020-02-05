### Docker Run
```
docker run \
--detach \
--name memcached \
memcached:latest

docker run \
--detach \
--name url-shortener \
--link memcached \
--publish 1816:1816 \
--env "HTTPD_SERVERNAME=**sub.do.main**" \
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

   url-shortener:
    image: bmoorman/url-shortener:latest
    container_name: url-shortener
    depends_on:
      - memcached
    ports:
      - "1816:1816"
    environment:
      - HTTPD_SERVERNAME=**sub.do.main**
    volumes:
      - url-shortener-config:/config

volumes:
  url-shortener-config:
```
