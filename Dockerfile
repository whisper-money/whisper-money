FROM php:8.4.27-cli

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    zip \
    unzip \
    procps \
    net-tools \
    netcat-openbsd \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl sockets
# Build redis from its GitHub release source so a PECL outage cannot block a build
RUN docker-php-source extract \
    && mkdir -p /usr/src/php/ext/redis \
    && curl -fsSL https://github.com/phpredis/phpredis/archive/refs/tags/6.3.0.tar.gz | tar xz --strip-components=1 -C /usr/src/php/ext/redis \
    && docker-php-ext-install redis \
    && docker-php-source delete

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install Bun
RUN curl -fsSL https://bun.com/install | bash
ENV PATH="/root/.bun/bin:${PATH}"

WORKDIR /app

# Install Playwright browsers for Pest browser tests
# Note: This requires node_modules to be installed first, so it's done at runtime
# or you can copy package files and run: bun install && bunx playwright install --with-deps chromium

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
