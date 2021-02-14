FROM php:7.4-cli

WORKDIR /workspace

RUN apt-get update && apt-get install -y \
  zip

RUN docker-php-ext-install \
  pcntl \
  pdo_mysql

COPY build /build
RUN /build/install-composer
RUN rm -fr /build

ENTRYPOINT ["build/entrypoint"]
