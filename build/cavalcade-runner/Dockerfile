ARG php_version
FROM php:${php_version}-cli

WORKDIR /workspace

RUN apt-get update && apt-get install -y \
  default-mysql-client \
  git \
  less \
  libzip-dev \
  procps \
  sudo \
  unzip \
  vim \
  wait-for-it \
  zip

RUN docker-php-ext-install \
  mysqli \
  pcntl \
  pdo_mysql \
  zip

COPY build /build

ARG uid
ARG gid
RUN /build/common/setup-user "${uid}" "${gid}"
RUN /build/common/install-composer
RUN /build/common/install-wpcli

RUN rm -fr /build

ENTRYPOINT ["build/cavalcade-runner/entrypoint"]
