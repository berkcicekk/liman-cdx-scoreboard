FROM aciklab/liman:latest
USER root
RUN apt-get update --allow-releaseinfo-change-label && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends iputils-ping python3 slapd openssl acl shellcheck && rm -rf /var/lib/apt/lists/*
ENTRYPOINT []
