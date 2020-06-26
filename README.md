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
    volumes:
      - url-shortener-config:/config

volumes:
  url-shortener-config:
```

### Environment Variables
* **TZ** Sets the timezone. Default `America/Denver`.
* **HTTPD_SERVERNAME** Sets the vhost servername. Default `localhost`.
* **HTTPD_PORT** Sets the vhost port. Default `1816`.
* **HTTPD_SSL** Set to anything other than `SSL` (e.g. `NO_SSL`) to disable SSL. Default `SSL`.
* **HTTPD_REDIRECT** Set to anything other than `REDIRECT` (e.g. `NO_REDIRECT`) to disable SSL redirect. Default `REDIRECT`.
* **MEMCACHED_HOST** Sets the Memcached host. Default `memcached`.
* **MEMCACHED_PORT** Sets the Memcached port. Default `11211`.
