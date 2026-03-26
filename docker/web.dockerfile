FROM nginx:1.29

# Copy public files (like css)
WORKDIR /var/www/youtube_rss
COPY public /var/www/youtube_rss/public

COPY /docker/vhost.conf /etc/nginx/conf.d/default.conf

RUN ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log