# Proxy di uscita della sandbox napay (TIP-020): il web resta su reti interne e raggiunge
# solo i gateway di pagamento elencati in squid.conf, in HTTPS (CONNECT 443).
FROM alpine:3.22.8
RUN apk add --no-cache squid=6.12-r0
COPY squid.conf /etc/squid/squid.conf
USER squid
EXPOSE 3128
CMD ["squid", "-N", "-f", "/etc/squid/squid.conf"]
