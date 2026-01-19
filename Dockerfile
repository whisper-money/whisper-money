FROM php:8.4-cli

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
RUN pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install Bun
RUN curl -fsSL https://bun.com/install | bash
ENV PATH="/root/.bun/bin:${PATH}"

WORKDIR /app

# Install Playwright browsers for Pest browser tests
# Note: This requires node_modules to be installed first, so it's done at runtime
# or you can copy package files and run: bun install && bunx playwright install --with-deps chromium

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
