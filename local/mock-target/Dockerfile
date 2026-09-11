FROM alpine:3.22
RUN apk add --no-cache nginx openssh supervisor \
    && mkdir -p /run/nginx /var/log/supervisor
COPY nginx.conf /etc/nginx/http.d/default.conf
COPY supervisord.conf /etc/supervisord.conf
EXPOSE 80 22
CMD ["sh", "-c", "ssh-keygen -A && exec supervisord -n -c /etc/supervisord.conf"]
